<?php

namespace App\Jobs;

use App\Models\EmailMessage;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SentMessage;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Entrega um e-mail ao servidor de envio (docker/mail) e anota o id da fila dele,
 * que liga a mensagem ao log de entrega. O ritmo é o do limitador `email` (o
 * provedor do servidor aceita poucos envios por minuto): passou dele, o job volta
 * para a fila e espera. Falha de conexão tenta de novo (1, 5 e 15 min) por até 2 h;
 * depois disso a mensagem fica como "falhou", com o erro.
 */
class SendEmail implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public function __construct(public int $messageId, public Mailable $mail)
    {
        $this->onQueue('notifications');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited('email')];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(Mailer $mailer): void
    {
        $message = EmailMessage::query()->find($this->messageId);

        if (! $message || $message->status !== 'na_fila') {
            return;
        }

        $message->increment('attempts');

        try {
            $sent = $mailer->to($message->to_address)->send($this->mail);
        } catch (Throwable $e) {
            $message->forceFill(['last_response' => Str::limit($e->getMessage(), 1000)])->save();

            // Recusa definitiva do servidor de envio (5xx: remetente ou destinatário inválido) não melhora com o tempo.
            if ($e instanceof TransportExceptionInterface && $e->getCode() >= 500 && $e->getCode() < 600) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        $queueId = $this->queueId($sent);

        $message->forceFill([
            'status' => 'aceito',
            'queue_id' => $queueId,
            'accepted_at' => now(),
            'last_response' => $queueId
                ? "aceito pelo servidor de envio (fila {$queueId})"
                : 'aceito pelo mailer '.config('mail.default').' (sem servidor de envio: não sai daqui)',
        ])->save();
    }

    public function failed(?Throwable $e): void
    {
        EmailMessage::query()->whereKey($this->messageId)->where('status', 'na_fila')->update([
            'status' => 'falhou',
            'failed_at' => now(),
            'last_response' => Str::limit((string) $e?->getMessage(), 1000) ?: 'o envio esgotou as tentativas',
        ]);
    }

    /** O Postfix responde ao DATA com "250 2.0.0 Ok: queued as <id>"; o id é o que aparece no log dele. */
    private function queueId(?SentMessage $sent): ?string
    {
        $debug = $sent?->getSymfonySentMessage()->getDebug() ?? '';

        return preg_match('/queued as ([0-9A-Za-z]+)/', $debug, $m) ? $m[1] : null;
    }
}
