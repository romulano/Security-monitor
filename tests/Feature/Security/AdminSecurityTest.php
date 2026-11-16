<?php

namespace Tests\Feature\Security;

use App\Jobs\ScanSecuritySite;
use App\Models\SecurityCheck;
use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Models\SecuritySite;
use App\Models\User;
use App\Services\Security\CheckResult;
use App\Services\Security\ScanRecorder;
use App\Services\Security\ScanReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Admin → Segurança: quem entra, o que se cadastra, o que a tela mostra (e escapa) e as ações. */
class AdminSecurityTest extends TestCase
{
    use RefreshDatabase, SecurityTestHelpers;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSecurity();
        Queue::fake();
        $this->admin = $this->admin();
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin);
    }

    private function record(SecuritySite $site, array $results): void
    {
        app(ScanRecorder::class)->record($site, ScanReport::build($results, 200, null, 900, true));
    }

    // ---- acesso ----------------------------------------------------------------

    public function test_common_users_are_refused_everywhere(): void
    {
        $site = $this->site(['user_id' => $this->admin->id]);
        $urls = [
            ['get', route('admin.security')], ['post', route('admin.security.store')],
            ['get', route('admin.security.show', $site)], ['post', route('admin.security.run', $site)],
            ['post', route('admin.security.toggle', $site)],
            ['delete', route('admin.security.destroy', $site)],
        ];

        $user = User::factory()->create();
        foreach ($urls as [$method, $url]) {
            $this->actingAs($user)->{$method}($url)->assertForbidden();
        }

        Queue::assertNothingPushed();
        $this->assertSame(1, SecuritySite::query()->count());
    }

    public function test_a_non_numeric_id_never_reaches_the_controller(): void
    {
        $this->asAdmin()->get('/admin/seguranca/abc')->assertNotFound();
        $this->asAdmin()->get('/admin/seguranca/1%27%20OR%201=1')->assertNotFound();
    }

    // ---- cadastro ------------------------------------------------------------------

    public function test_the_admin_registers_a_site_with_consent(): void
    {
        $this->dns->set('acme.com.br', 'ips', ['104.21.1.1']);

        $response = $this->asAdmin()->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post(route('admin.security.store'), ['host' => 'HTTPS://Acme.com.br/entrar', 'consent' => '1']);

        $site = SecuritySite::query()->sole();
        $response->assertRedirect(route('admin.security.show', $site));

        $this->assertSame($this->admin->id, $site->user_id);
        $this->assertSame('acme.com.br', $site->host);
        $this->assertSame('198.51.100.7', $site->consent_ip);
        $this->assertNotNull($site->consent_at);
        $this->assertSame(360, $site->interval_minutes);
        $this->assertTrue($site->next_check_at->lte(now()));

        $this->asAdmin()->get(route('admin.security.show', $site))
            ->assertOk()
            ->assertDontSee('Comprove que o domínio é seu');
    }

    public function test_it_refuses_without_the_consent_checkbox(): void
    {
        $this->asAdmin()->post(route('admin.security.store'), ['host' => 'acme.com.br'])
            ->assertSessionHasErrors('consent');

        $this->assertSame(0, SecuritySite::query()->count());
    }

    public function test_it_refuses_malformed_input(): void
    {
        foreach (['[::1]', 'acme.com.br:9200', 'admin:senha@acme.com.br', "acme.com.br\r\nX-Injetado: 1", 'meu_site.acme.com.br', ''] as $input) {
            $this->asAdmin()->from(route('admin.security'))->post(route('admin.security.store'), ['host' => $input, 'consent' => '1'])
                ->assertRedirect(route('admin.security'));
        }

        $this->assertSame(0, SecuritySite::query()->count());
        $this->asAdmin()->from(route('admin.security'))->post(route('admin.security.store'), ['host' => "acme.com.br\r\nX-Injetado: 1", 'consent' => '1'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Endereço inválido'));
    }

    /** Instalação local, de um dono só: IP, nome de container e nome interno agora são alvo válido. */
    public function test_it_accepts_ip_and_internal_names(): void
    {
        foreach (['127.0.0.1', 'localhost', '10.0.0.5', '169.254.169.254', '0x7f000001', '2130706433', 'secmon-db', 'es', 'hermes', 'servidor.internal'] as $input) {
            $this->asAdmin()->from(route('admin.security'))->post(route('admin.security.store'), ['host' => $input, 'consent' => '1'])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(10, SecuritySite::query()->count());
    }

    public function test_a_duplicate_is_refused_with_a_message(): void
    {
        $this->site(['host' => 'acme.com.br']);

        $this->asAdmin()->from(route('admin.security'))->post(route('admin.security.store'), ['host' => 'acme.com.br', 'consent' => '1'])
            ->assertSessionHas('error', 'acme.com.br já está na lista.');

        $this->assertSame(1, SecuritySite::query()->count());
    }

    // ---- telas -----------------------------------------------------------------------

    public function test_the_list_shows_state_score_and_problems(): void
    {
        $ok = $this->site(['host' => 'bom.com.br']);
        $bad = $this->site(['host' => 'ruim.com.br']);
        $this->record($ok, [CheckResult::ok('tls.valid')]);
        $this->record($bad, [CheckResult::critical('file.exposed', 'x', '/.env'), CheckResult::warning('http.hsts', 'ausente')]);

        $this->asAdmin()->get(route('admin.security'))
            ->assertOk()
            ->assertSee('bom.com.br')->assertSee('100/100')->assertSee('Em ordem')
            ->assertSee('ruim.com.br')->assertSee('71/100')->assertSee('Crítico')->assertSee('1 crítico(s)');
    }

    public function test_the_list_turns_red_when_a_site_has_gone_two_days_without_a_scan(): void
    {
        $site = $this->site();
        $this->record($site, [CheckResult::ok('tls.valid')]);
        $this->asAdmin()->get(route('admin.security'))->assertDontSee('Sem varredura há mais de 48 h');

        $site->forceFill(['last_check_at' => now()->subHours(49)])->save();

        $this->asAdmin()->get(route('admin.security'))->assertSee('Sem varredura há mais de 48 h');
        $this->asAdmin()->get(route('admin.security.show', $site))->assertSee('sem verificação há mais de 48 h');
    }

    public function test_the_detail_page_groups_results_and_shows_the_advice_only_for_problems(): void
    {
        $site = $this->site();
        $this->record($site, [
            CheckResult::ok('tls.valid', 'emitido por Let\'s Encrypt'),
            CheckResult::warning('http.hsts', 'ausente'),
            CheckResult::warning('cookie.flags', 'falta Secure', 'sessionid'),
            CheckResult::untested('dom.expiry', 'o registro não informa o vencimento'),
        ]);

        $page = $this->asAdmin()->get(route('admin.security.show', $site))->assertOk();

        $page->assertSee('Certificado e TLS')->assertSee('HTTPS e cabeçalhos')->assertSee('Cookies e conteúdo')
            ->assertSee('Certificado confiável')->assertSee('emitido por Let&#039;s Encrypt', false)
            ->assertSee('Sem Strict-Transport-Security', false)->assertDontSee('O certificado vence em breve')
            ->assertSee('sessionid')->assertSee('falta Secure')
            ->assertSee('Não testável')->assertSee('Nota')->assertSee('Problemas abertos');
    }

    public function test_everything_that_came_from_the_target_site_is_escaped_in_the_panel(): void
    {
        $site = $this->site();
        $evil = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
        $this->record($site, [
            CheckResult::warning('leak.server', 'Server: '.$evil),
            CheckResult::warning('cookie.flags', 'falta '.$evil, $evil),
        ]);

        $html = $this->asAdmin()->get(route('admin.security.show', $site))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html);
    }

    public function test_what_got_worse_since_the_previous_scan_comes_first(): void
    {
        $site = $this->site();
        $this->record($site, [CheckResult::ok('tls.expiry', 'vence em 80 dias')]);
        $this->travel(7)->hours();
        $this->record($site, [CheckResult::critical('tls.expiry', 'vence em 5 dia(s)'), CheckResult::warning('http.hsts', 'ausente')]);

        $this->asAdmin()->get(route('admin.security.show', $site))
            ->assertSee('O que piorou desde a verificação anterior')
            ->assertSee('Validade do certificado')
            ->assertSee('apareceu');
    }

    public function test_the_first_scan_has_nothing_to_compare_with_so_nothing_got_worse(): void
    {
        $site = $this->site();
        $this->record($site, [CheckResult::warning('hdr.csp', 'ausente'), CheckResult::warning('hdr.coop', 'ausente')]);

        $this->asAdmin()->get(route('admin.security.show', $site))
            ->assertDontSee('O que piorou desde a verificação anterior')
            ->assertSee('Primeira verificação deste site')
            ->assertSee('Content-Security-Policy');
    }

    public function test_only_what_got_worse_in_the_last_scan_is_listed_not_the_previous_ones(): void
    {
        $site = $this->site();
        $this->record($site, [CheckResult::warning('http.hsts', 'ausente')]);
        $this->travel(6)->hours();
        $this->record($site, [CheckResult::warning('http.hsts', 'ausente'), CheckResult::warning('hdr.csp', 'ausente')]);

        $box = $this->boxOf($this->asAdmin()->get(route('admin.security.show', $site))->getContent());
        $this->assertStringContainsString('Content-Security-Policy', $box);
        $this->assertStringNotContainsString('HSTS', $box, 'o HSTS apareceu na varredura anterior, não nesta');

        // Nada mudou na terceira: o quadro some.
        $this->travel(6)->hours();
        $this->record($site, [CheckResult::warning('http.hsts', 'ausente'), CheckResult::warning('hdr.csp', 'ausente')]);
        $this->asAdmin()->get(route('admin.security.show', $site))->assertDontSee('O que piorou desde a verificação anterior');
    }

    /** O trecho do quadro "O que piorou" na página. */
    private function boxOf(string $html): string
    {
        $start = strpos($html, 'O que piorou desde a verificação anterior');
        $this->assertNotFalse($start, 'o quadro não apareceu');

        return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }

    public function test_a_site_with_no_scan_yet_says_so(): void
    {
        $site = $this->site();

        $this->asAdmin()->get(route('admin.security.show', $site))->assertOk()->assertSee('Ainda não houve verificação');
    }

    public function test_a_scan_that_could_not_reach_the_site_shows_the_reason(): void
    {
        $site = $this->site();
        app(ScanRecorder::class)->recordFailure($site, 'o site não respondeu');

        $this->asAdmin()->get(route('admin.security.show', $site))->assertSee('não conseguiu falar com o site')->assertSee('o site não respondeu');
    }

    // ---- ações -----------------------------------------------------------------------------

    public function test_verify_now_queues_the_scan(): void
    {
        $site = $this->site();

        $this->asAdmin()->post(route('admin.security.run', $site))->assertRedirect()->assertSessionHas('status');
        Queue::assertPushedOn('security', ScanSecuritySite::class, fn ($job) => $job->siteId === $site->id);

        $this->asAdmin()->post(route('admin.security.run', $site))->assertSessionHas('status');
        Queue::assertPushed(ScanSecuritySite::class, 2);
    }

    public function test_a_paused_site_cannot_be_verified_by_hand_and_pause_and_resume_toggle(): void
    {
        $site = $this->site();

        $this->asAdmin()->post(route('admin.security.toggle', $site))->assertSessionHas('status', 'acme.com.br pausado: nenhuma verificação até retomar.');
        $this->assertFalse($site->fresh()->enabled);

        $this->asAdmin()->post(route('admin.security.run', $site))->assertSessionHas('error', fn ($m) => str_contains($m, 'está pausado'));
        Queue::assertNothingPushed();

        $site->forceFill(['next_check_at' => now()->addDay()])->save();
        $this->asAdmin()->post(route('admin.security.toggle', $site))->assertSessionHas('status', 'acme.com.br voltou para a monitoria.');
        $this->assertTrue($site->fresh()->enabled);
        $this->assertTrue($site->fresh()->next_check_at->lte(now()->addSecond()), 'retomar agenda a verificação para já');
    }

    public function test_removing_the_site_removes_its_history(): void
    {
        $site = $this->site();
        $this->record($site, [CheckResult::warning('http.hsts', 'ausente')]);

        $this->asAdmin()->delete(route('admin.security.destroy', $site))->assertRedirect(route('admin.security'))->assertSessionHas('status');

        $this->assertSame([0, 0, 0, 0], [SecuritySite::query()->count(), SecurityCheck::query()->count(), SecurityFinding::query()->count(), SecurityEvent::query()->count()]);
    }

    public function test_the_verify_button_is_rate_limited_per_admin(): void
    {
        $site = $this->site();

        foreach (range(1, 20) as $_) {
            $this->asAdmin()->post(route('admin.security.run', $site));
        }

        $this->asAdmin()->post(route('admin.security.run', $site))->assertStatus(429);
    }

    // ---- painel do admin ---------------------------------------------------------------

    public function test_the_admin_dashboard_has_no_security_card_until_a_site_exists(): void
    {
        $this->asAdmin()->get(route('admin.dashboard'))->assertOk()->assertDontSee('Segurança dos sites');
    }

    public function test_the_dashboard_card_shows_the_worst_state_and_goes_red_when_the_scheduler_stops(): void
    {
        $site = $this->site();
        $this->record($site, [CheckResult::critical('tls.expiry', 'vence em 3 dia(s)')]);

        $this->asAdmin()->get(route('admin.dashboard'))
            ->assertOk()->assertSee('Segurança dos sites')->assertSee('1 site vigiado(s)', false)->assertSee('1 problema(s) crítico(s)')
            ->assertSee('text-red-300', false);

        $site->forceFill(['last_check_at' => now()->subHours(50), 'last_status' => 'ok'])->save();
        SecurityFinding::query()->update(['status' => 'resolved']);

        $this->asAdmin()->get(route('admin.dashboard'))->assertSee('sem varredura há mais de 48 h')->assertSee('text-red-300', false);
    }

    public function test_the_menu_has_the_security_entry(): void
    {
        $this->asAdmin()->get(route('admin.dashboard'))->assertSee(route('admin.security'))->assertSee('Segurança');
    }
}
