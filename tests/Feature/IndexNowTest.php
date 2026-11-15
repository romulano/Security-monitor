<?php

namespace Tests\Feature;

use App\Jobs\SubmitIndexNow;
use App\Models\NewsCase;
use App\Models\User;
use App\Services\IndexNow\IndexNow;
use App\Services\Merge\CaseMerger;
use App\Services\Search\CaseIndex;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Alerts\AlertsTestHelpers;
use Tests\TestCase;

/**
 * IndexNow (2026-11-01): a matéria nova vai na hora a Bing, Yandex e cia., num
 * lote por rodada, sem repetir o endereço na mesma hora, pela fila de baixa
 * prioridade. Nos testes fica desligado, a não ser no teste que liga.
 */
class IndexNowTest extends TestCase
{
    use AlertsTestHelpers;
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.indexnow.org/indexnow';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://example.com']);
        Settings::flush();
        // O Elasticsearch não faz parte da suíte.
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
    }

    private function turnOn(): void
    {
        Settings::set(IndexNow::SETTING, true);
    }

    private function story(NewsCase $case): string
    {
        return 'https://example.com/materia/'.$case->uuid;
    }

    public function test_a_new_published_case_is_announced_to_indexnow(): void
    {
        $this->turnOn();
        Http::fake([self::ENDPOINT => Http::response('', 200)]);

        $case = $this->newsCase('Nginx RCE exploited in the wild');

        $key = (string) config('secmon.indexnow.key');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINT
            && $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/json; charset=utf-8')
            && $request->data() === [
                'host' => 'example.com',
                'key' => $key,
                'keyLocation' => 'https://example.com/'.$key.'.txt',
                'urlList' => [$this->story($case)],
            ]);

        $last = app(IndexNow::class)->status()['last'];
        $this->assertSame([1, 200, null], [$last['urls'], $last['status'], $last['error']]);
    }

    public function test_indexnow_stays_off_in_the_tests_and_away_from_home(): void
    {
        Queue::fake();

        $this->newsCase('Nginx RCE exploited in the wild');
        $this->assertFalse(config('secmon.indexnow.enabled'));
        Queue::assertNotPushed(SubmitIndexNow::class);

        // Ligado, mas com o site num endereço de casa: o buscador recusaria, e o aviso nem sai.
        $this->turnOn();
        config(['app.url' => 'http://localhost:1515']);
        $this->newsCase('Ivanti zero-day');
        Queue::assertNotPushed(SubmitIndexNow::class);
        Http::assertNothingSent();
    }

    public function test_the_round_goes_in_one_batch_on_the_low_queue_and_an_address_waits_an_hour(): void
    {
        Queue::fake();
        $this->turnOn();

        $cases = [$this->newsCase('First'), $this->newsCase('Second'), $this->newsCase('Third')];

        // Um job só na fila, o de baixa prioridade, e depois de a rodada assentar.
        Queue::assertPushed(SubmitIndexNow::class, 1);
        Queue::assertPushedOn('notifications-low', SubmitIndexNow::class);

        // Sair do feed e voltar na mesma hora não repete o endereço.
        $cases[0]->hide('admin');
        $cases[0]->unhide();

        Http::fake([self::ENDPOINT => Http::response('', 202)]);
        $this->assertSame(3, app(IndexNow::class)->flush());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['urlList'] === array_map($this->story(...), $cases));

        // Passada a hora, a mesma matéria volta a valer um aviso (saiu do feed: 410).
        $this->travel(61)->minutes();
        $cases[0]->hide('admin');
        $this->assertSame(1, app(IndexNow::class)->status()['pending']);
    }

    public function test_the_fusion_announces_the_new_case_and_each_old_address(): void
    {
        $one = $this->newsCase('Cloudflare sees attacks on two PHP flaws');
        $two = $this->newsCase('PHP fixes two critical RCE flaws');
        Queue::fake();
        $this->turnOn();

        $new = app(CaseMerger::class)->merge([$one->id, $two->id], [
            'title' => 'PHP2Shell: two critical PHP RCE flaws face early attacks',
            'summary' => 'The PHP team fixes two critical flaws.',
            'title_pt' => null,
            'summary_pt' => null,
        ]);

        Http::fake([self::ENDPOINT => Http::response('', 200)]);
        app(IndexNow::class)->flush();

        // A nova (200) e as antigas, que agora redirecionam para ela (301).
        Http::assertSent(fn (Request $request) => $request['urlList'] === [$this->story($new), $this->story($one), $this->story($two)]);
    }

    public function test_a_temporary_failure_keeps_the_addresses_and_a_refusal_drops_them(): void
    {
        Queue::fake();
        $this->turnOn();
        $indexNow = app(IndexNow::class);
        $this->newsCase('Nginx RCE exploited in the wild');

        Http::fake([self::ENDPOINT => Http::sequence()->push('', 503)->push('', 200)->push('', 403)]);

        try {
            $indexNow->flush();
            $this->fail('o 503 tinha de subir, para o job tentar de novo');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }

        $this->assertSame(1, $indexNow->status()['pending'], 'o endereço espera a próxima tentativa');
        $this->assertSame(1, $indexNow->flush());
        $this->assertSame(0, $indexNow->status()['pending']);

        // Recusa (a chave não confere): repetir não adianta, o lote sai da fila e o motivo fica.
        $this->newsCase('Ivanti zero-day');
        $this->assertSame(0, $indexNow->flush());
        $status = $indexNow->status();
        $this->assertSame(0, $status['pending']);
        $this->assertSame(403, $status['last']['status']);
        $this->assertStringContainsString('chave', $status['last']['error']);
        Http::assertSentCount(3);
    }

    public function test_the_job_retries_on_the_low_queue_without_blocking_anything(): void
    {
        $job = new SubmitIndexNow;

        $this->assertSame('notifications-low', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());
        $this->assertLessThanOrEqual(15, (int) config('secmon.indexnow.timeout'));
    }

    public function test_the_key_file_is_public_and_holds_the_key(): void
    {
        $key = (string) config('secmon.indexnow.key');

        // O formato do protocolo: de 8 a 128 letras, números e hífens.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9-]{8,128}$/', $key);
        $this->assertFileExists(public_path($key.'.txt'));
        $this->assertSame($key, trim((string) file_get_contents(public_path($key.'.txt'))));
    }

    public function test_the_admin_sees_the_last_announcement_and_turns_it_off(): void
    {
        // O resto da página (o estado do hermes, a lista de modelos) responde qualquer coisa.
        Http::fake([self::ENDPOINT => Http::response('', 202), '*' => Http::response()]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Avisar os buscadores na hora (IndexNow)')
            ->assertSee('Nenhum aviso ainda.')
            ->assertSee('/'.config('secmon.indexnow.key').'.txt');

        $this->turnOn();
        $this->newsCase('Nginx RCE exploited in the wild');

        $this->actingAs($admin)->get(route('admin.settings'))->assertOk()->assertSee('aceito (HTTP 202)');

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'crawler_enabled' => 1,
            'crawler_max_items' => 25,
            'crawler_timeout' => 20,
            'crawler_dedup_threshold' => 0.62,
            'crawler_user_agent' => 'SecurityMonitorBot/1.0',
            'enricher_enabled' => 1,
            'enricher_driver' => 'heuristic',
            'enricher_claude_model' => 'claude-sonnet-5-5',
            'enricher_openai_model' => 'gpt-4o-mini',
            'enricher_hermes_url' => 'http://hermes.test:8765',
            'indexnow_enabled' => 0,
        ])->assertSessionHasNoErrors();

        Settings::flush();
        $this->assertFalse(app(IndexNow::class)->enabled());
    }
}
