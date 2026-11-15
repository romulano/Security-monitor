<?php

namespace Tests\Feature\Lookalike;

use App\Jobs\CheckLookalikeCertificates;
use App\Jobs\CheckLookalikeNames;
use App\Jobs\FinishLookalikeScan;
use App\Jobs\ScanLookalikes;
use App\Models\LookalikeDomain;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Lookalike\LookalikeScanner;
use App\Services\Lookalike\NameExistence;
use App\Services\Lookalike\ScanRun;
use App\Services\Lookalike\VariantGenerator;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\TestCase;

/** Varredura completa em lotes de jobs: lotes, retry de cada um, trava e o último job que junta tudo. */
class LookalikeBatchScanTest extends TestCase
{
    use LookalikeTestHelpers, RefreshDatabase;

    private const CAP = 450;

    private FakeDnsResolver $dns;

    /** O que o crt.sh responde: 200 com o certificado de exemplo, ou o erro daqui. */
    private int $crtshStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTempBrowserDir();
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        config([
            'secmon.lookalikes.max_variants' => self::CAP,
            'secmon.lookalikes.dns_batch_size' => 100,
            'secmon.lookalikes.dns_retry_pause_ms' => 0,
        ]);
        Http::fake([
            'crt.sh/*' => fn () => $this->crtshStatus === 200
                ? Http::response([['name_value' => 'acmepay-seguro.top']])
                : Http::response('<html>502 Bad Gateway</html>', $this->crtshStatus),
            '*' => Http::response('', 404),
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBrowserDir();
        parent::tearDown();
    }

    public function test_a_full_scan_is_split_into_dns_batches_on_the_radar_queue_and_certificates_run_beside_them(): void
    {
        Bus::fake();
        $watch = $this->watch();

        (new ScanLookalikes($watch->id))->handle(app(LookalikeScanner::class));

        $names = count((new VariantGenerator)->generate('acmepay.com.br', ['Acme Pay', 'Acme Pagamentos'], ['acmepay.com.br'], max: self::CAP));

        Bus::assertBatched(function (PendingBatch $batch) use ($names) {
            $chunks = $batch->jobs->filter(fn ($job) => $job instanceof CheckLookalikeNames);
            $certs = $batch->jobs->filter(fn ($job) => $job instanceof CheckLookalikeCertificates);

            return $batch->queue() === 'lookalikes'
                && $batch->allowsFailures()
                && $chunks->count() === (int) ceil($names / 100)
                && $chunks->every(fn (CheckLookalikeNames $job) => count($job->candidates) <= 100 && $job->queue === 'lookalikes')
                && $chunks->sum(fn (CheckLookalikeNames $job) => count($job->candidates)) === $names
                && $certs->count() === 1
                && $batch->jobs->count() === $chunks->count() + 1;
        });
        $this->assertTrue(ScanRun::running($watch->id), 'a trava fica até o último job terminar');
        $this->assertSame('running', $watch->refresh()->scan_status);
    }

    public function test_known_names_are_not_asked_again_in_the_batches(): void
    {
        Bus::fake();
        $watch = $this->watch();
        $this->lookalike($watch, 'acmepay.com');

        app(LookalikeScanner::class)->scanInBatches($watch);

        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs
            ->filter(fn ($job) => $job instanceof CheckLookalikeNames)
            ->every(fn (CheckLookalikeNames $job) => ! in_array('acmepay.com', array_column($job->candidates, 0), true)));
    }

    public function test_batches_the_certificates_and_the_last_job_give_the_same_result_as_the_direct_scan(): void
    {
        // QUEUE_CONNECTION=sync nos testes: os lotes e o último job rodam na hora, na ordem da fila.
        $this->dns->set('acmepay.com.br', 'ns', ['a.ns.cliente.example'])->set('acmepay.com.br', 'ips', ['198.51.100.7'])
            ->set('acmepay.com', 'ns', ['ns1.barato.example'])->set('acmepay.com', 'ips', ['45.90.10.50'])
            ->set('acmpay.shop', 'ns', ['ns1.barato.example'])
            ->set('acmepay-seguro.top', 'ns', ['ns1.x.example'])->set('acmepay-seguro.top', 'ips', ['45.90.10.60']);
        config(['secmon.lookalikes.dns_batch_size' => 40, 'secmon.lookalikes.max_variants' => 3000]);

        $watch = app(LookalikeScanner::class)->enable($this->watch());
        app(LookalikeScanner::class)->scanInBatches($watch->refresh());
        $watch->refresh();

        $this->assertSame('done', $watch->scan_status);
        $this->assertSame([
            'acmepay-seguro.top' => 'pending',
            'acmepay.com' => 'pending',
            'acmpay.shop' => 'no_site',
        ], LookalikeDomain::query()->orderBy('host')->pluck('state', 'host')->all());
        $this->assertSame('terminacao', LookalikeDomain::query()->where('host', 'acmepay.com')->value('techniques')[0]);
        $this->assertSame('omissao', LookalikeDomain::query()->where('host', 'acmpay.shop')->value('techniques')[0], 'erro de digitação fora de .com e .com.br');
        $this->assertTrue(LookalikeDomain::query()->where('host', 'acmepay-seguro.top')->value('from_certificate'));

        $batch = DB::table('job_batches')->first();
        $this->assertSame(0, (int) $batch->failed_jobs);
        $this->assertSame(0, (int) $batch->pending_jobs);
        $this->assertNotNull($batch->finished_at);

        $log = collect($watch->last_log)->pluck('text')->implode("\n");
        $this->assertMatchesRegularExpression('/\d+ variações geradas para acmepay\.com\.br\./', $log);
        $this->assertMatchesRegularExpression('/\d+ variações em \d+ lotes conferidas no DNS em \d+ s\./', $log);
        $this->assertStringContainsString('3 existem no DNS (3 novos desde a última varredura).', $log);
        $this->assertSame(3, $watch->stats['registered']);
        $this->assertFalse(ScanRun::running($watch->id), 'a trava solta no fim');
        $this->assertCount(1, array_keys($this->dns->asked, 'ns acmepay.net', true), 'cada nome gerado é perguntado uma vez');
    }

    public function test_a_name_the_dns_did_not_answer_is_asked_again_and_never_taken_as_missing(): void
    {
        $this->dns->set('acmepay.com', 'ns', ['ns1.barato.example']);
        $this->dns->failing['acmepay.com'] = 1;
        $this->dns->failing['acmepay.net'] = 99;

        $result = app(NameExistence::class)->checkCandidates([['acmepay.com', 'terminacao'], ['acmepay.net', 'terminacao'], ['acmepay.org', 'terminacao']]);

        $this->assertSame(['acmepay.com' => 'terminacao'], $result['found'], 'respondeu na segunda pergunta');
        $this->assertSame(1, $result['unanswered'], 'o que nunca respondeu não é "não existe"');
        $this->assertSame([['host' => 'acmepay.org', 'technique' => 'terminacao']], $result['free'], 'e também não é "livre para registrar"');
        $this->assertContains('falhou acmepay.com', $this->dns->asked);
    }

    public function test_free_names_keep_the_generator_order_even_when_some_needed_a_second_try(): void
    {
        config(['secmon.lookalikes.free_variants' => 2]);
        $this->dns->failing['acmepay.net'] = 1;

        $result = app(NameExistence::class)->checkCandidates([['acmepay.net', 'terminacao'], ['acmepay.org', 'terminacao'], ['acmepay.app', 'terminacao']]);

        $this->assertSame(['acmepay.net', 'acmepay.org'], array_column($result['free'], 'host'));
    }

    public function test_a_batch_with_a_mute_dns_fails_to_retry_later_and_keeps_what_it_got_on_the_last_try(): void
    {
        $candidates = array_map(fn (string $s) => ["acmepay.{$s}", 'terminacao'], ['com', 'net', 'org', 'app', 'xyz']);

        foreach ($candidates as [$host]) {
            $this->dns->failing[$host] = 99;
        }

        $scanner = app(LookalikeScanner::class);

        try {
            $scanner->checkNames('run-1', 0, $candidates, final: false);
            $this->fail('o lote com o DNS mudo deveria falhar para o job tentar de novo');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('5 de 5', $e->getMessage());
        }

        $this->assertNull(ScanRun::get('run-1', 'chunk.0'));

        $scanner->checkNames('run-1', 0, $candidates, final: true);
        $this->assertSame(5, ScanRun::get('run-1', 'chunk.0')['unanswered']);
    }

    public function test_a_few_unanswered_names_do_not_fail_the_batch(): void
    {
        $candidates = array_map(fn (int $i) => ["acmepay{$i}.com", 'acrescimo'], range(1, 20));
        $this->dns->failing['acmepay3.com'] = 99;

        app(LookalikeScanner::class)->checkNames('run-2', 0, $candidates, final: false);

        $this->assertSame(1, ScanRun::get('run-2', 'chunk.0')['unanswered']);
    }

    public function test_each_job_has_its_own_retry_and_runs_on_the_radar_queue(): void
    {
        config(['secmon.lookalikes.dns_batch_tries' => 3, 'secmon.lookalikes.dns_batch_backoff' => [30, 120]]);

        foreach ([new CheckLookalikeNames(1, 'r', 0, []), new CheckLookalikeCertificates(1, 'r', 'acmepay', [])] as $job) {
            $this->assertSame(3, $job->tries());
            $this->assertSame([30, 120], $job->backoff());
            $this->assertSame('lookalikes', $job->queue);
        }

        $this->assertSame('lookalikes', (new FinishLookalikeScan(1, 'r'))->queue);
    }

    public function test_crtsh_flaky_fails_the_certificate_job_to_retry_but_the_last_try_keeps_the_error(): void
    {
        $this->crtshStatus = 502;
        $scanner = app(LookalikeScanner::class);

        try {
            $scanner->checkCertificates('run-3', 'acmepay', ['acmepay.com.br'], final: false);
            $this->fail('o crt.sh fora do ar deveria falhar o job para tentar de novo');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('crt.sh', $e->getMessage());
        }

        $scanner->checkCertificates('run-3', 'acmepay', ['acmepay.com.br'], final: true);
        $this->assertStringContainsString('crt.sh', ScanRun::get('run-3', 'certs')['error']);

        // Nome curto não é erro do crt.sh: tentar de novo não adianta, então não falha.
        $scanner->checkCertificates('run-4', 'acme', ['acme.com.br'], final: false);
        $this->assertStringContainsString('curto', ScanRun::get('run-4', 'certs')['error']);
    }

    public function test_a_failed_batch_and_unanswered_names_are_reported_and_the_scan_still_finishes(): void
    {
        $watch = $this->watch();
        $runId = ScanRun::begin($watch->id);
        ScanRun::put($runId, 'meta', ['started_at' => now()->subSeconds(75)->toIso8601String(), 'variants' => ['acmepay.com', 'acmepay.net'], 'chunks' => 2, 'log' => []]);
        ScanRun::put($runId, 'chunk.0', ['found' => ['acmepay.com' => 'terminacao'], 'free' => [], 'unanswered' => 3, 'checked' => 5]);
        // O lote 1 esgotou as tentativas e não guardou nada.
        ScanRun::put($runId, 'certs', ['hosts' => [], 'found' => [], 'error' => 'crt.sh indisponível (HTTP 502)']);
        $this->dns->set('acmepay.com', 'ns', ['ns1.barato.example'])->set('acmepay.com', 'ips', ['45.90.10.50']);

        app(LookalikeScanner::class)->complete($watch, $runId);
        $watch->refresh();

        $log = collect($watch->last_log)->pluck('text')->implode("\n");
        $this->assertSame('done', $watch->scan_status);
        $this->assertStringContainsString('1 lote falhou e 3 nomes ficaram sem resposta do DNS; voltam na próxima varredura.', $log);
        $this->assertStringContainsString('conferidas no DNS em 1 min 15 s', $log);
        $this->assertStringContainsString('Certificados: crt.sh indisponível (HTTP 502). A busca volta na próxima varredura.', $log);
        $this->assertSame(3, $watch->stats['unanswered']);
        $this->assertSame(['acmepay.com'], LookalikeDomain::query()->pluck('host')->all());
        $this->assertFalse(ScanRun::running($watch->id));
    }

    public function test_a_scan_that_lost_its_state_fails_cleanly_and_releases_the_lock(): void
    {
        $watch = $this->watch();
        $runId = ScanRun::begin($watch->id);

        app(LookalikeScanner::class)->complete($watch, $runId);

        $this->assertSame('failed', $watch->refresh()->scan_status);
        $this->assertStringContainsString('perdeu o estado', $watch->last_error);
        $this->assertFalse(ScanRun::running($watch->id));
    }

    public function test_the_scheduler_and_the_other_scans_wait_while_the_batches_are_running(): void
    {
        $watch = $this->watch();
        $watch->update(['next_scan_at' => now()->subMinute()]);
        $runId = ScanRun::begin($watch->id);
        $this->assertNull(ScanRun::begin($watch->id), 'só uma varredura por Radar');

        Bus::fake();
        $this->artisan('secmon:lookalikes-scan --due')->assertSuccessful();
        Bus::assertNothingDispatched();

        app(LookalikeScanner::class)->scanInBatches($watch);
        Bus::assertNothingBatched();

        app(LookalikeScanner::class)->recheck($watch);
        $this->assertSame([], $this->dns->asked, 'a releitura não corre junto com os lotes');

        ScanRun::end($watch->id, $runId);
        $this->artisan('secmon:lookalikes-scan --due')->assertSuccessful();
        Bus::assertDispatched(ScanLookalikes::class, fn (ScanLookalikes $job) => $job->watchId === $watch->id && $job->full);
    }

    public function test_the_last_job_releases_the_lock_when_it_dies(): void
    {
        $watch = $this->watch();
        $runId = ScanRun::begin($watch->id);
        $watch->update(['scan_status' => 'running']);

        (new FinishLookalikeScan($watch->id, $runId))->failed(new RuntimeException('tempo esgotado'));

        $this->assertFalse(ScanRun::running($watch->id));
        $this->assertSame('failed', $watch->refresh()->scan_status);
        $this->assertStringContainsString('tempo esgotado', $watch->last_error);
    }

    public function test_a_batch_that_ran_out_of_time_stops_asking_and_reports_the_rest_as_unanswered(): void
    {
        config(['secmon.lookalikes.dns_batch_budget_seconds' => 0]);

        $result = app(NameExistence::class)->check(['acmepay.com', 'acmepay.net']);

        $this->assertSame([], $this->dns->asked, 'um nome mudo custa ~30 s por pergunta: sem orçamento, não pergunta mais');
        $this->assertSame(['acmepay.com', 'acmepay.net'], $result['unanswered']);
        $this->assertSame([], $result['missing']);
    }

    public function test_the_last_job_waits_for_a_piece_that_has_not_arrived_instead_of_joining_half_the_scan(): void
    {
        Bus::fake();
        $watch = $this->watch(attributes: ['scan_status' => 'running']);
        $runId = ScanRun::begin($watch->id);
        ScanRun::put($runId, 'meta', ['started_at' => now()->toIso8601String(), 'variants' => ['acmepay.com'], 'chunks' => 2, 'log' => []]);
        ScanRun::put($runId, 'chunk.0', ['found' => [], 'free' => [], 'unanswered' => 0, 'checked' => 1]);
        // O lote 1 e os certificados ainda não deixaram nada (batch terminou antes da hora).
        $this->assertSame(2, ScanRun::missing($runId));

        (new FinishLookalikeScan($watch->id, $runId))->handle(app(LookalikeScanner::class));

        Bus::assertDispatched(FinishLookalikeScan::class, fn (FinishLookalikeScan $job) => $job->waits === 1 && $job->runId === $runId);
        $this->assertSame('running', $watch->refresh()->scan_status, 'não juntou nada ainda');
        $this->assertTrue(ScanRun::running($watch->id), 'a trava fica');

        // Esperou o que podia: junta o que há e conta o que faltou.
        (new FinishLookalikeScan($watch->id, $runId, FinishLookalikeScan::MAX_WAITS))->handle(app(LookalikeScanner::class));

        $watch->refresh();
        $this->assertSame('done', $watch->scan_status);
        $this->assertStringContainsString('1 lote falhou', collect($watch->last_log)->pluck('text')->implode("\n"));
        $this->assertFalse(ScanRun::running($watch->id));
    }

    public function test_a_job_that_gave_up_leaves_a_notice_so_the_last_job_does_not_wait_for_it(): void
    {
        Bus::fake();
        $watch = $this->watch(attributes: ['scan_status' => 'running']);
        $runId = ScanRun::begin($watch->id);
        ScanRun::put($runId, 'meta', ['started_at' => now()->toIso8601String(), 'variants' => ['acmepay.com'], 'chunks' => 2, 'log' => []]);
        ScanRun::put($runId, 'chunk.0', ['found' => [], 'free' => [], 'unanswered' => 0, 'checked' => 1]);

        (new CheckLookalikeNames($watch->id, $runId, 1, []))->failed(new RuntimeException('DNS mudo'));
        (new CheckLookalikeCertificates($watch->id, $runId, 'acmepay', []))->failed(new RuntimeException('crt.sh'));

        $this->assertSame(0, ScanRun::missing($runId));
        (new FinishLookalikeScan($watch->id, $runId))->handle(app(LookalikeScanner::class));

        Bus::assertNotDispatched(FinishLookalikeScan::class);
        $watch->refresh();
        $log = collect($watch->last_log)->pluck('text')->implode("\n");
        $this->assertSame('done', $watch->scan_status);
        $this->assertStringContainsString('1 lote falhou', $log);
        $this->assertStringContainsString('Certificados: a busca em certificados falhou.', $log);
    }

    public function test_four_dedicated_workers_for_the_radar_queue_and_nobody_else_reads_it(): void
    {
        $programs = [];

        foreach (array_slice(preg_split('/^\[program:/m', file_get_contents(base_path('docker/supervisor/secmon.conf'))), 1) as $block) {
            preg_match('/^([\w-]+)\]/', $block, $name);
            preg_match('/^command=(.+)$/m', $block, $command);
            preg_match('/^numprocs=(\d+)/m', $block, $procs);
            $programs[$name[1]] = ['command' => $command[1], 'numprocs' => (int) ($procs[1] ?? 1)];
        }

        $this->assertSame(4, $programs['fila-radar']['numprocs']);
        preg_match('/--queue=(\S+)/', $programs['fila-radar']['command'], $queue);
        $this->assertSame('lookalikes', $queue[1], 'o worker do Radar lê só a fila do Radar');

        foreach (array_diff_key($programs, ['fila-radar' => 1]) as $name => $program) {
            $this->assertStringNotContainsString('lookalikes', $program['command'], "{$name} não deve ler a fila do Radar");
        }
    }
}
