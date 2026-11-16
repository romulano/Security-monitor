<?php

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Mail\TestEmail;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Mail\DeliveryLog;
use App\Services\Mail\Outbox;
use App\Services\Maintenance\RetentionPruner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Todo e-mail passa pelo Outbox (registro + fila), o job entrega ao servidor de envio
 * e anota o id da fila, e o log do Postfix diz o que o servidor do destinatário fez.
 */
class EmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** Linhas reais do Postfix 3.10 do docker/mail (entregue e adiado) e as de devolução e de prazo vencido. */
    private const LOG = <<<'LOG'
Oct 24 20:58:04 mail postfix/smtpd[127]: 71CE0E25F48: client=app.secmon_default[172.26.0.4]
Oct 24 20:58:04 mail postfix/smtp[131]: 71CE0E25F48: to=<ok@exemplo.test>, relay=gmail-smtp-in.l.google.com[142.250.0.27]:25, delay=0.21, delays=0.18/0.02/0/0, dsn=2.0.0, status=sent (250 2.0.0 OK  1729803484 - gsmtp)
Oct 24 20:58:16 mail postfix/smtp[131]: 275AEE25F54: to=<adiado@exemplo.test>, relay=none, delay=5.2, delays=0.18/0/5/0, dsn=4.4.3, status=deferred (Host or domain name not found. Name service error for name=exemplo.test type=MX: Host not found, try again)
Oct 24 21:00:00 mail postfix/smtp[131]: 9F1A2B3C4D5: to=<nao@exemplo.test>, relay=mx.exemplo.test[203.0.113.5]:25, delay=1.1, delays=0.1/0/0.5/0.5, dsn=5.1.1, status=bounced (host mx.exemplo.test[203.0.113.5] said: 550 5.1.1 <nao@exemplo.test>: Recipient address rejected: User unknown (in reply to RCPT TO command))
Oct 25 21:00:00 mail postfix/qmgr[100]: 1A2B3C4D5E6: from=<bot@example.com>, status=expired, returned to sender

LOG;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = tempnam(sys_get_temp_dir(), 'maillog');
        config(['secmon.mail.log_path' => $this->logPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);

        parent::tearDown();
    }

    private function message(array $attributes = []): EmailMessage
    {
        return EmailMessage::create($attributes + ['kind' => 'teste', 'to_address' => 'ok@exemplo.test', 'subject' => 'Security Monitor: teste de envio']);
    }

    /** Um mailer que responde como o Postfix ao DATA, com o id da fila no transcript. */
    private function mailerAnswering(string $debug): Mailer
    {
        $email = (new Email)->from('bot@example.com')->to('ok@exemplo.test')->text('x');
        $symfony = new SymfonySentMessage($email, new Envelope(new Address('bot@example.com'), [new Address('ok@exemplo.test')]));
        $symfony->appendDebug($debug);

        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->andReturn(new SentMessage($symfony));
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('to')->with('ok@exemplo.test')->andReturn($pending);

        return $mailer;
    }

    public function test_the_outbox_registers_the_message_and_queues_it(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $message = app(Outbox::class)->send(new TestEmail('24/10/2026 18:00'), 'ok@exemplo.test', 'teste', $user);

        $this->assertSame('na_fila', $message->fresh()->status);
        $this->assertSame('Security Monitor: teste de envio', $message->subject);
        $this->assertSame($user->id, $message->user_id);
        Queue::assertPushedOn('notifications', SendEmail::class, fn (SendEmail $job) => $job->messageId === $message->id);
    }

    public function test_the_job_hands_the_message_to_the_server_and_keeps_the_queue_id(): void
    {
        $message = $this->message();

        (new SendEmail($message->id, new TestEmail('agora')))->handle($this->mailerAnswering("> DATA\r\n< 354 End data with <CR><LF>.<CR><LF>\r\n< 250 2.0.0 Ok: queued as 71CE0E25F48\r\n"));

        $message->refresh();
        $this->assertSame('aceito', $message->status);
        $this->assertSame('71CE0E25F48', $message->queue_id);
        $this->assertSame(1, $message->attempts);
        $this->assertNotNull($message->accepted_at);
    }

    public function test_without_the_server_the_log_mailer_is_said_on_the_message(): void
    {
        config(['mail.default' => 'array']);
        $message = $this->message();

        app()->call([new SendEmail($message->id, new TestEmail('agora')), 'handle']);

        $message->refresh();
        $this->assertSame('aceito', $message->status);
        $this->assertNull($message->queue_id);
        $this->assertStringContainsString('sem servidor de envio', $message->last_response);
    }

    public function test_a_temporary_error_is_kept_and_retried_and_a_permanent_one_fails_at_once(): void
    {
        $message = $this->message();
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->andThrow(new TransportException('Expected response code "250" but got code "451", with message "451 4.3.0 tente depois".', 451));
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('to')->andReturn($pending);

        try {
            (new SendEmail($message->id, new TestEmail('agora')))->handle($mailer);
            $this->fail('o erro passageiro tinha que voltar para a fila');
        } catch (TransportException) {
        }

        $this->assertSame('na_fila', $message->fresh()->status);
        $this->assertStringContainsString('451 4.3.0', $message->fresh()->last_response);

        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->andThrow(new TransportException('got code "554", with message "554 5.7.1 Sender address rejected"', 554));
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('to')->andReturn($pending);

        $job = (new SendEmail($message->id, new TestEmail('agora')))->withFakeQueueInteractions();
        $job->handle($mailer);
        $job->assertFailed();
    }

    public function test_exhausted_attempts_mark_the_message_as_failed(): void
    {
        $message = $this->message(['last_response' => 'Connection could not be established with host "mail:25"']);

        (new SendEmail($message->id, new TestEmail('agora')))->failed(new TransportException('Connection could not be established with host "mail:25"'));

        $message->refresh();
        $this->assertSame('falhou', $message->status);
        $this->assertNotNull($message->failed_at);
        $this->assertStringContainsString('mail:25', $message->last_response);
    }

    public function test_the_delivery_log_marks_delivered_deferred_and_bounced(): void
    {
        $sent = $this->message(['status' => 'aceito', 'queue_id' => '71CE0E25F48']);
        $deferred = $this->message(['status' => 'aceito', 'queue_id' => '275AEE25F54']);
        $bounced = $this->message(['status' => 'aceito', 'queue_id' => '9F1A2B3C4D5']);
        $expired = $this->message(['status' => 'adiado', 'queue_id' => '1A2B3C4D5E6']);
        file_put_contents($this->logPath, self::LOG);

        $this->assertSame(4, app(DeliveryLog::class)->sync());

        $this->assertSame('entregue', $sent->fresh()->status);
        $this->assertNotNull($sent->fresh()->delivered_at);
        $this->assertStringContainsString('250 2.0.0 OK', $sent->fresh()->last_response);
        $this->assertSame('adiado', $deferred->fresh()->status);
        $this->assertStringContainsString('4.4.3 Host or domain name not found', $deferred->fresh()->last_response);
        $this->assertSame('devolvido', $bounced->fresh()->status);
        $this->assertStringContainsString('User unknown', $bounced->fresh()->last_response);
        $this->assertSame('devolvido', $expired->fresh()->status);

        // Continua de onde parou: nada novo, nada aplicado; linha nova, só ela.
        $this->assertSame(0, app(DeliveryLog::class)->sync());
        file_put_contents($this->logPath, "Oct 24 21:05:00 mail postfix/smtp[131]: 275AEE25F54: to=<adiado@exemplo.test>, relay=mx[203.0.113.9]:25, delay=300, delays=0/0/1/1, dsn=2.0.0, status=sent (250 ok)\n", FILE_APPEND);
        $this->assertSame(1, app(DeliveryLog::class)->sync());
        $this->assertSame('entregue', $deferred->fresh()->status);
    }

    public function test_a_late_deferred_line_does_not_undo_a_final_status_and_a_rotated_log_starts_over(): void
    {
        $message = $this->message(['status' => 'entregue', 'queue_id' => '275AEE25F54']);
        file_put_contents($this->logPath, self::LOG);
        app(DeliveryLog::class)->sync();

        $this->assertSame('entregue', $message->fresh()->status);

        // Rodou: o arquivo novo é menor que a posição guardada.
        $other = $this->message(['status' => 'aceito', 'queue_id' => 'ABCDEF01234']);
        file_put_contents($this->logPath, "Oct 26 08:00:00 mail postfix/smtp[9]: ABCDEF01234: to=<x@exemplo.test>, relay=mx[203.0.113.9]:25, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (250 ok)\n");
        $this->assertSame(1, app(DeliveryLog::class)->sync());
        $this->assertSame('entregue', $other->fresh()->status);
    }

    public function test_the_reader_runs_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'secmon:mail-status'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_old_messages_are_pruned(): void
    {
        $old = $this->message(['created_at' => now()->subDays(91)]);
        $recent = $this->message(['created_at' => now()->subDays(5)]);

        app(RetentionPruner::class)->run();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }
}
