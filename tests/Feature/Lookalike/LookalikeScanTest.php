<?php

namespace Tests\Feature\Lookalike;

use App\Jobs\ScanLookalikes;
use App\Models\LookalikeCapture;
use App\Models\LookalikeDomain;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Lookalike\LookalikeScanner;
use App\Services\Lookalike\VariantGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Feature\Pentest\PentestRunFixtures;
use Tests\TestCase;

/** Varredura: variações e certificados no DNS, registro, e o pedido ao navegador. */
class LookalikeScanTest extends TestCase
{
    use LookalikeTestHelpers, PentestRunFixtures, RefreshDatabase;

    private const CAP = 450;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTempBrowserDir();
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        // Teto baixo: o teste confere as variações que importam (as terminações, as parecidas e as palavras de golpe), não as ~13 mil.
        config(['secmon.lookalikes.max_variants' => self::CAP, 'secmon.lookalikes.dns_retry_pause_ms' => 0]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->tearDownBrowserDir();
        parent::tearDown();
    }

    private function fakeInternet(array $crtsh = [], int $crtshStatus = 200): void
    {
        Http::fake([
            'crt.sh/*' => $crtshStatus === 200 ? Http::response($crtsh) : Http::response('<html>502 Bad Gateway</html>', $crtshStatus),
            'data.iana.org/rdap/dns.json' => Http::response(['services' => [
                [['com', 'net'], ['https://rdap.verisign.example/com/v1/']],
                [['br'], ['https://rdap.registro.br/']],
                [['top'], ['https://rdap.nic.top/']],
            ]]),
            'data.iana.org/rdap/ipv4.json' => Http::response(['services' => [
                [['45.0.0.0/8'], ['https://rdap.lacnic.example/rdap/']],
                [['177.0.0.0/8'], ['https://rdap.outra.example/']],
            ]]),
            'data.iana.org/rdap/ipv6.json' => Http::response(['services' => []]),
            'rdap.verisign.example/com/v1/domain/acmepay.com' => Http::response([
                'events' => [['eventAction' => 'registration', 'eventDate' => now()->subDays(3)->toIso8601String()]],
                'entities' => [[
                    'roles' => ['registrar'],
                    'vcardArray' => ['vcard', [['fn', [], 'text', 'Registrador Barato LLC']]],
                    'entities' => [['roles' => ['abuse'], 'vcardArray' => ['vcard', [['email', [], 'text', 'Abuse@Barato.example']]]]],
                ]],
            ]),
            'rdap.registro.br/domain/*' => Http::response([
                'events' => [['eventAction' => 'registration', 'eventDate' => '2015-01-10T00:00:00Z']],
                // Titular pessoa física: nunca é guardado.
                'entities' => [['roles' => ['registrant'], 'vcardArray' => ['vcard', [['fn', [], 'text', 'Fulano de Tal']]]]],
            ]),
            'rdap.lacnic.example/rdap/ip/*' => Http::response([
                'name' => 'HOSPEDA-NET',
                'entities' => [
                    ['roles' => ['registrant'], 'vcardArray' => ['vcard', [['fn', [], 'text', 'Hospeda Tudo Ltda']]]],
                    ['roles' => ['abuse'], 'vcardArray' => ['vcard', [['email', [], 'text', 'abuse@hospeda.example']]]],
                ],
            ]),
            'rdap.outra.example/ip/*' => Http::response(['name' => 'OUTRA-NET']),
            '*' => Http::response('', 404),
        ]);
    }

    public function test_full_scan_finds_registered_names_and_asks_the_browser_only_for_public_sites(): void
    {
        $this->fakeInternet([
            ['name_value' => "acmepay-seguro.top\n*.acmepay-seguro.top"],
            ['name_value' => 'acmepay.conta-segura.com'],
            ['name_value' => 'www.acmepay.com.br'],
        ]);

        $this->dns->set('acmepay.com.br', 'ns', ['a.ns.cliente.example', 'b.ns.cliente.example'])
            ->set('acmepay.com.br', 'ips', ['198.51.100.7'])
            // Golpe novo: registrado há 3 dias, com site e e-mail.
            ->set('acmepay.com', 'ns', ['ns1.barato.example'])
            ->set('acmepay.com', 'ips', ['45.90.10.50'])
            ->set('acmepay.com', 'mx', ['mx.barato.example'])
            // Registrado sem site.
            ->set('acmepay.net', 'ns', ['ns1.barato.example'])
            // Aponta para dentro (10.x): nunca vai para o navegador.
            ->set('acrnepay.com.br', 'ns', ['ns1.barato.example'])
            ->set('acrnepay.com.br', 'ips', ['10.0.0.5'])
            // Registro defensivo do cliente: mesmos servidores de DNS.
            ->set('acmepay-login.com.br', 'ns', ['a.ns.cliente.example', 'b.ns.cliente.example'])
            // Dos certificados.
            ->set('acmepay-seguro.top', 'ns', ['ns1.x.example'])
            ->set('acmepay-seguro.top', 'ips', ['45.90.10.60'])
            ->set('acmepay.conta-segura.com', 'ips', ['45.90.10.61']);

        $watch = app(LookalikeScanner::class)->enable($this->watch());
        app(LookalikeScanner::class)->scan($watch->refresh());
        $watch->refresh();

        $found = LookalikeDomain::query()->pluck('state', 'host')->all();
        ksort($found);
        $this->assertSame([
            'acmepay-login.com.br' => 'no_site',
            'acmepay-seguro.top' => 'pending',
            'acmepay.com' => 'pending',
            'acmepay.conta-segura.com' => 'pending',
            'acmepay.net' => 'no_site',
            'acrnepay.com.br' => 'no_site',
        ], $found);

        $com = LookalikeDomain::query()->where('host', 'acmepay.com')->first();
        $this->assertSame('terminacao', $com->techniques[0]);
        $this->assertTrue($com->registered_at->isSameDay(now()->subDays(3)));
        $this->assertSame('Registrador Barato LLC', $com->rdap['registrar']);
        $this->assertSame('abuse@barato.example', $com->rdap['abuse_email']);
        $this->assertSame('Hospeda Tudo Ltda', $com->hosting['org']);
        $this->assertSame('abuse@hospeda.example', $com->hosting['abuse_email']);
        $this->assertContains('mx', array_column($com->signals, 'key'));
        $this->assertContains('recent', array_column($com->signals, 'key'));

        $br = LookalikeDomain::query()->where('host', 'acmepay-login.com.br')->first();
        $this->assertSame('low', $br->risk, 'mesmos NS do cliente: registro defensivo');
        $this->assertSame('Registro.br', $br->rdap['registrar']);
        $this->assertStringNotContainsString('Fulano', json_encode($br->rdap));

        $this->assertTrue(LookalikeDomain::query()->where('host', 'acmepay-seguro.top')->value('from_certificate'));

        // Um lote no inbox, só com os de IP público, e os domínios do cliente bloqueados.
        $files = glob($this->browserDir.'/inbox/*.json');
        $this->assertCount(1, $files);
        $request = json_decode(file_get_contents($files[0]), true);
        $this->assertSame(['acmepay.com.br'], $request['block_hosts']);
        $hosts = array_column($request['items'], 'host');
        sort($hosts);
        $this->assertSame(['acmepay-seguro.top', 'acmepay.com', 'acmepay.conta-segura.com'], $hosts);
        $this->assertSame(3, LookalikeCapture::query()->where('status', 'queued')->count());

        // Livres para registro defensivo: nenhum dos que existem.
        $free = array_column($watch->free_variants, 'host');
        $this->assertNotEmpty($free);
        $this->assertNotContains('acmepay.com', $free);
        $this->assertSame('done', $watch->scan_status);
        $this->assertSame('01:00', $watch->next_scan_at->timezone('America/Sao_Paulo')->format('H:i'), 'a completa volta todo dia à 01:00');
        $this->assertTrue($watch->next_scan_at->isFuture());
        $this->assertTrue($watch->next_scan_at->lte(now()->addDay()));
    }

    public function test_recheck_reads_only_known_names_and_does_not_ask_twice(): void
    {
        $this->fakeInternet();
        $this->dns->set('acmepay.com', 'ns', ['ns1.barato.example'])->set('acmepay.com', 'ips', ['45.90.10.50']);
        $watch = $this->watch();
        $this->lookalike($watch, 'acmepay.com', ['rdap_checked_at' => now()]);

        app(LookalikeScanner::class)->recheck($watch);
        app(LookalikeScanner::class)->recheck($watch);

        $this->assertSame(1, LookalikeCapture::query()->where('status', 'queued')->count(), 'pedido na fila não é repetido');
        $this->assertNotContains('ns acmepay.net', $this->dns->asked, 'releitura não gera variações');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'crt.sh'));
    }

    public function test_cdn_changing_ips_in_the_same_range_is_not_a_change(): void
    {
        $this->fakeInternet();
        $watch = $this->watch();
        $lookalike = $this->lookalike($watch, 'acmepay.com', [
            'ips' => ['45.90.10.50'],
            'hosting' => ['ip' => '45.90.10.50', 'network' => 'HOSPEDA-NET'],
            'rdap_checked_at' => now(),
        ]);
        $capture = $lookalike->captures()->create(['status' => 'done', 'requested_at' => now(), 'captured_at' => now(), 'state' => 'content']);
        $lookalike->update(['last_capture_id' => $capture->id, 'state' => 'content']);

        $this->lookalike($watch, 'acmepay.net', ['ips' => [], 'state' => 'no_site', 'rdap_checked_at' => now()]);
        $this->dns->set('acmepay.net', 'ns', ['ns1.barato.example']);
        $this->dns->set('acmepay.com', 'ns', ['ns1.barato.example'])->set('acmepay.com', 'ips', ['45.90.10.51']);
        app(LookalikeScanner::class)->recheck($watch);
        $this->assertSame(0, LookalikeCapture::query()->where('status', 'queued')->count());

        // Sem IP antes e depois não é mudança (e não vira linha no log).
        $this->assertStringNotContainsString('IP mudou', json_encode($watch->refresh()->last_log, JSON_UNESCAPED_UNICODE));

        // Mudou de rede: o navegador vai lá de novo.
        $this->dns->set('acmepay.com', 'ips', ['177.10.20.30']);
        app(LookalikeScanner::class)->recheck($watch);
        $this->assertSame(1, LookalikeCapture::query()->where('status', 'queued')->count());
    }

    public function test_dns_that_does_not_answer_keeps_what_was_known_instead_of_erasing_the_site(): void
    {
        $this->fakeInternet();
        $watch = $this->watch();
        $lookalike = $this->lookalike($watch, 'acmepay.com', [
            'state' => 'offline',
            'ips' => ['45.90.10.50'],
            'ns' => ['ns1.barato.example'],
            'mx' => ['mx.barato.example'],
            'rdap_checked_at' => now(),
            'last_checked_at' => now()->subDay(),
        ]);
        $capture = $lookalike->captures()->create(['status' => 'done', 'requested_at' => now()->subDays(8), 'captured_at' => now()->subDays(8), 'state' => 'offline']);
        $lookalike->update(['last_capture_id' => $capture->id]);
        $before = $lookalike->fresh()->last_checked_at;

        // Timeout/SERVFAIL (resolvedor sobrecarregado): "não sei" não é "sem IP" nem "sumiu do DNS".
        $this->dns->failing['acmepay.com'] = 10;
        app(LookalikeScanner::class)->recheck($watch);

        $lookalike->refresh();
        $this->assertSame('offline', $lookalike->state, 'não vira "sem site" nem "não existe mais"');
        $this->assertSame(['45.90.10.50'], $lookalike->ips);
        $this->assertSame(['ns1.barato.example'], $lookalike->ns);
        $this->assertSame(['mx.barato.example'], $lookalike->mx);
        $this->assertTrue($lookalike->last_checked_at->equalTo($before), 'sem resposta, não conta como relido');
        $this->assertSame(1, LookalikeCapture::query()->where('status', 'queued')->count(), 'a visita vencida continua sendo pedida ao navegador');

        // O DNS volta: lê de verdade.
        $this->dns->failing = [];
        $this->dns->set('acmepay.com', 'ns', ['ns2.novo.example'])->set('acmepay.com', 'ips', ['45.90.10.99']);
        app(LookalikeScanner::class)->recheck($watch);

        $lookalike->refresh();
        $this->assertSame(['45.90.10.99'], $lookalike->ips);
        $this->assertSame(['ns2.novo.example'], $lookalike->ns);
        $this->assertTrue($lookalike->last_checked_at->isAfter($before));
    }

    public function test_client_site_prints_are_requested_monthly_only_with_the_v3_term(): void
    {
        $this->fakeInternet();
        $this->dns->set('acmepay.com', 'ns', ['ns1.barato.example'])->set('acmepay.com', 'ips', ['45.90.10.50']);

        // Termo v2: o Radar funciona, mas não abre o site do cliente.
        $old = $this->watch($this->verifiedDomain(consent: 'v2'));
        app(LookalikeScanner::class)->recheck($old);
        $this->assertSame('no_consent', $old->refresh()->reference_status);
        $this->assertCount(0, glob($this->browserDir.'/inbox/*.json'));

        $watch = $this->watch($this->verifiedDomain(name: 'acmepay.com.br', consent: 'v3'));
        $this->lookalike($watch, 'acmepay.com', ['rdap_checked_at' => now()]);
        app(LookalikeScanner::class)->recheck($watch);

        $files = glob($this->browserDir.'/inbox/*.json');
        sort($files);
        $this->assertCount(2, $files, 'referência e domínios, nessa ordem');
        $reference = json_decode(file_get_contents($files[0]), true);
        $this->assertSame('reference', $reference['mode']);
        $this->assertSame('https://acmepay.com.br/', $reference['site']['url']);
        $this->assertSame(3, $reference['site']['pages']);
        $this->assertStringContainsString('SecurityMonitorBot', $reference['site']['ua_suffix']);
        $this->assertSame('lookalikes', json_decode(file_get_contents($files[1]), true)['mode']);
        $this->assertSame('pending', $watch->refresh()->reference_status);

        // Na mesma semana não pede de novo.
        $watch->update(['reference_status' => 'done', 'reference_at' => now()->subDays(3)]);
        app(LookalikeScanner::class)->recheck($watch);
        $this->assertSame('done', $watch->refresh()->reference_status);
    }

    public function test_client_site_prints_wait_while_a_security_test_runs_on_the_site(): void
    {
        // T10: com um teste de segurança no domínio, os prints do site do cliente ficam para a próxima varredura.
        $this->fakeInternet();
        $this->dns->set('acmepay.com', 'ns', ['ns1.barato.example'])->set('acmepay.com', 'ips', ['45.90.10.50']);
        $watch = $this->watch($this->verifiedDomain(name: 'acmepay.com.br', consent: 'v3'));
        $this->lookalike($watch, 'acmepay.com', ['rdap_checked_at' => now()]);
        $run = $this->activeRun($watch->domain->user, 'acmepay.com.br');

        app(LookalikeScanner::class)->recheck($watch);

        $modes = array_map(fn (string $file) => json_decode(file_get_contents($file), true)['mode'], glob($this->browserDir.'/inbox/*.json'));
        $this->assertSame(['lookalikes'], $modes, 'só os domínios parecidos; a referência espera');
        $this->assertNotSame('pending', $watch->refresh()->reference_status);
        $this->assertStringContainsString('Prints do seu site: adiados, porque há um teste de segurança em andamento nele.', json_encode($watch->last_log, JSON_UNESCAPED_UNICODE));

        // Terminado o teste, a próxima varredura pede os prints.
        $this->moveRun($run, 'completed');
        app(LookalikeScanner::class)->recheck($watch->refresh());
        $this->assertSame('pending', $watch->refresh()->reference_status);
    }

    public function test_every_browser_batch_carries_the_addresses_of_the_monitor_itself(): void
    {
        // O que o .env de produção traz (o IP do servidor e a faixa IPv6 dele), mais um valor torto.
        config(['secmon.own_addresses' => ['203.0.113.10', '2001:db8:66:21cf::/64', 'nao-e-ip', '10.0.0.0/99', '203.0.113.10']]);
        $this->fakeInternet();
        $this->dns->set('acmepay.com', 'ns', ['ns1.barato.example'])->set('acmepay.com', 'ips', ['45.90.10.50']);
        $watch = $this->watch($this->verifiedDomain(consent: 'v3'));
        $this->lookalike($watch, 'acmepay.com', ['rdap_checked_at' => now()]);

        app(LookalikeScanner::class)->recheck($watch);

        $files = glob($this->browserDir.'/inbox/*.json');
        $this->assertCount(2, $files, 'referência e domínios');

        // O navegador não tem .env: a lista vai no próprio pedido, nos dois tipos, só com o que é IP ou faixa.
        foreach ($files as $file) {
            $request = json_decode(file_get_contents($file), true);
            $this->assertSame(['203.0.113.10', '2001:db8:66:21cf::/64'], $request['block_ips'], $request['mode']);
        }
    }

    public function test_blank_pages_and_reported_domains_are_opened_again_every_day(): void
    {
        $this->fakeInternet();
        $watch = $this->watch();
        $visited = fn (string $state, array $extra = []) => tap($this->lookalike($watch, "acmepay-{$state}.com", $extra + ['state' => $state, 'rdap_checked_at' => now()]), function ($lookalike) {
            $this->dns->set($lookalike->host, 'ns', ['ns1.x.example'])->set($lookalike->host, 'ips', ['45.90.20.10']);
            $capture = $lookalike->captures()->create(['status' => 'done', 'requested_at' => now()->subHours(21), 'captured_at' => now()->subHours(21), 'state' => $lookalike->state]);
            $lookalike->update(['last_capture_id' => $capture->id]);
        });

        $blank = $visited('blank');
        $content = $visited('content');
        $reported = $visited('reported', ['state' => 'content', 'reports' => ['registrar' => ['at' => now()->toIso8601String()]]]);

        app(LookalikeScanner::class)->recheck($watch);

        $queued = LookalikeCapture::query()->where('status', 'queued')->pluck('lookalike_id')->all();
        $this->assertContains($blank->id, $queued, 'página em branco: o navegador volta todo dia');
        $this->assertContains($reported->id, $queued, 'denunciado: volta todo dia até cair');
        $this->assertNotContains($content->id, $queued, 'site no ar sem mudança: 1 vez por semana');
    }

    public function test_activated_radar_waits_for_the_txt_and_starts_when_the_domain_is_verified(): void
    {
        // Domínio comprovado na entrevista de notícias não cria Radar sozinho: o Radar nasce na entrevista dele.
        $profile = $this->profile();
        $news = $profile->domains()->create(['domain' => 'acmepay.com.br', 'verify_token' => 'x', 'consent_at' => now(), 'consent_version' => 'v3']);
        $news->update(['verified_at' => now()]);
        $this->assertSame(0, LookalikeWatch::query()->count());
        $this->assertSame($profile->user_id, $news->refresh()->user_id, 'o domínio do alerta é do dono do alerta');

        $domain = MonitoredDomain::query()->create(['user_id' => $profile->user_id, 'domain' => 'acmepay.com.br', 'verify_token' => 'y']);
        $watch = $this->watch($domain, ['status' => 'waiting', 'next_scan_at' => null, 'last_scan_at' => null, 'scan_status' => 'pending']);

        Queue::fake();
        $this->artisan('secmon:lookalikes-scan --due')->assertSuccessful();
        Queue::assertNothingPushed();

        $domain->update(['verified_at' => now()]);
        $watch->refresh();
        $this->assertSame('active', $watch->status);
        $this->assertTrue($watch->next_scan_at->lte(now()), 'a primeira varredura fica para a próxima rodada do agendador');

        $this->artisan('secmon:lookalikes-scan --due')->assertSuccessful();
        Queue::assertPushed(ScanLookalikes::class, fn (ScanLookalikes $job) => $job->watchId === $watch->id && $job->full);

        $domain->update(['verified_at' => null]);
        $this->assertSame('waiting', $watch->refresh()->status, 'sem a posse, volta a esperar o TXT');
    }

    public function test_unverified_domain_is_never_scanned(): void
    {
        $this->fakeInternet();
        $domain = $this->verifiedDomain();
        $watch = app(LookalikeScanner::class)->enable($this->watch($domain));
        $domain->update(['verified_at' => null]);

        app(LookalikeScanner::class)->scan($watch->refresh());

        $this->assertSame('failed', $watch->refresh()->scan_status);
        $this->assertSame([], $this->dns->asked);
        $this->assertSame(0, LookalikeDomain::query()->count());
    }

    public function test_crtsh_down_does_not_break_the_scan(): void
    {
        $this->fakeInternet(crtshStatus: 502);

        $watch = app(LookalikeScanner::class)->enable($this->watch());
        app(LookalikeScanner::class)->scan($watch->refresh());

        $watch->refresh();
        $this->assertSame('done', $watch->scan_status);
        $this->assertStringContainsString('crt.sh', $watch->stats['certificates_error']);
        $this->assertSame(count((new VariantGenerator)->generate('acmepay.com.br', ['Acme Pay', 'Acme Pagamentos'], max: self::CAP)), $watch->stats['variants']);
    }
}
