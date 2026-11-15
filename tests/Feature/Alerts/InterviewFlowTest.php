<?php

namespace Tests\Feature\Alerts;

use App\Models\AlertProfile;
use App\Models\MonitoredDomain;
use App\Models\TechCatalog;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\InterviewService;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\ProfilePreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Etapa 2: a entrevista de ponta a ponta, pelo HTTP. */
class InterviewFlowTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private FakeProfileIndex $index;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = new FakeProfileIndex;
        // Salvar o domínio no passo 5 lê o DNS público dele (sugestões): nunca a rede de verdade.
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->app->instance(ProfileIndex::class, $this->index);
        $this->mock(ProfilePreview::class)->shouldReceive('run')->andReturn([
            'total' => 2, 'by_level' => ['critical' => 1, 'high' => 1], 'per_week' => 0.5, 'error' => null,
            'examples' => [['uuid' => null, 'title' => 'FortiOS: falha explorada', 'criticity' => 'critical', 'latest_at' => now()->toIso8601String(), 'why' => 'Fortinet (FortiGate)', 'brand' => false]],
        ]);
    }

    private function step(int $n, array $answers, array $extra = [])
    {
        return $this->put(route('alerts.interview.update', $n), ['answers' => $answers] + $extra);
    }

    public function test_without_profile_the_page_invites_to_the_interview(): void
    {
        $this->actingAs($this->client())
            ->get(route('alerts.news'))
            ->assertOk()
            ->assertSee('Começar a entrevista');
    }

    public function test_each_step_renders_its_questions(): void
    {
        $this->actingAs($this->client());

        $this->get(route('alerts.interview', 1))->assertOk()->assertSee('Nome da empresa')->assertSee('Setor de atuação');
        $this->get(route('alerts.interview', 2))->assertOk()->assertSee('Quais frameworks?')->assertSee('data-parent-group="php"', false)
            ->assertSeeInOrder(['data-parent-group="php"', 'PHP', 'Laravel'], false);
        $this->get(route('alerts.interview', 3))->assertOk()->assertSee('Tipo de servidor')->assertSee('Ubuntu');
        $this->get(route('alerts.interview', 4))->assertOk()->assertSee('Firewall, VPN e equipamentos de borda');
        $this->get(route('alerts.interview', 5))->assertOk()->assertSee('Vocês têm um domínio próprio?');
        $this->get(route('alerts.interview', 6))->assertOk()->assertSee('A partir de qual nível');
        $this->get(route('alerts.interview', 7))->assertOk()->assertSee('Revise antes de ativar');
        $this->get(route('alerts.interview', 8))->assertNotFound();
    }

    /** Quem não desenvolve também tem site: WordPress e Lovable não dependem de marcar linguagem. */
    public function test_anyone_can_mark_wordpress_and_lovable_as_platforms(): void
    {
        $user = $this->client();
        $this->actingAs($user);
        $this->step(2, ['languages' => ['none']]);

        // Item cadastrado sozinho pela descoberta (veio do site de outro cliente) não vira opção.
        TechCatalog::query()->create([
            'slug' => 'auto-wp-plugin-segredo', 'name' => 'WordPress plugin: Segredo Do Cliente', 'category' => 'cms',
            'terms' => ['segredo'], 'detect' => [], 'active' => true, 'strict' => true, 'origin' => 'discovery', 'discovered_on' => 'outro.com.br',
        ]);

        $this->get(route('alerts.interview', 2))->assertOk()->assertDontSee('WordPress');
        $this->get(route('alerts.interview', 4))->assertOk()
            ->assertSeeInOrder(['Site, loja e plataformas', 'WordPress', 'Lovable', 'Ferramentas da empresa'])
            ->assertSee('Criador de sites e apps')
            ->assertDontSee('Segredo Do Cliente');

        $this->step(4, ['platforms' => ['wordpress', 'lovable']])->assertSessionHasNoErrors();

        $profile = AlertProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['interview', 'interview'], $profile->techs()->whereIn('slug', ['wordpress', 'lovable'])->pluck('alert_profile_techs.source')->all());
        // "lovable" também é adjetivo: só conta quando a notícia o lista como produto afetado.
        $this->assertTrue($profile->techs()->where('slug', 'lovable')->first()->strict);
    }

    public function test_full_interview_builds_the_profile(): void
    {
        $user = $this->client();
        $this->actingAs($user);

        $this->step(1, [
            'company_name' => 'Acme Pagamentos',
            'brands' => ['Acme Pay', ' acme pay ', 'AcmeConta', ''],
            'sectors' => ['financeiro', 'tecnologia'],
            'regions' => ['brasil'],
        ])->assertRedirect(route('alerts.interview', 2));

        $this->step(2, [
            'languages' => ['php', 'javascript'],
            'uses_framework' => 'sim',
            'frameworks' => ['laravel', 'nextjs'],
            'devops' => ['github'],
        ])->assertRedirect(route('alerts.interview', 3));

        $this->step(3, ['server_os' => 'linux', 'linux_distros' => ['ubuntu'], 'web_servers' => ['nginx', 'cloudflare']]);
        $this->step(4, ['edge' => ['fortinet'], 'identity' => ['google-workspace'], 'custom_terms' => ['Metabase', 'n8n']]);
        $this->step(5, ['has_domain' => 'nao']);
        $this->step(6, ['min_criticity' => 'critical', 'attack_types' => ['ransomware'], 'brand_mentions' => '1', 'sector_news' => '0', 'case_updates' => '1', 'language' => 'pt']);

        $profile = AlertProfile::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('draft', $profile->status);
        $this->assertSame('Acme Pagamentos', $profile->company_name);
        $this->assertSame(['Acme Pay', 'AcmeConta'], $profile->brands);
        $this->assertSame(['financeiro', 'tecnologia'], $profile->sectors);
        $this->assertSame('critical', $profile->min_criticity);
        $this->assertSame('linux', $profile->server_os);
        $this->assertEqualsCanonicalizing(
            ['php', 'javascript', 'laravel', 'nextjs', 'github', 'linux', 'ubuntu', 'nginx', 'cloudflare', 'fortinet', 'google-workspace'],
            $profile->techs->pluck('slug')->all(),
        );
        $this->assertEqualsCanonicalizing(['Metabase', 'n8n'], $profile->terms->pluck('value')->all());
        $this->assertStringContainsString('criticity:(critical)', $profile->compiled_query);
        $this->assertSame([], $this->index->stored, 'rascunho não vai para o percolator');

        $this->get(route('alerts.interview', 7))->assertOk()->assertSee('2 alertas')->assertSee('FortiOS: falha explorada');

        $this->post(route('alerts.interview.finish'))->assertRedirect();

        $profile->refresh();
        $this->assertSame('active', $profile->status);
        $this->assertNotNull($profile->completed_at);
        $this->assertSame($profile->match_query, $this->index->stored[$profile->id]);

        $this->get(route('alerts.news'))->assertOk()->assertSee('Notícias')->assertSee('Somente Critical');
    }

    /** Regressão: o JSON das regras ia cru para o atributo e quebrava no primeiro ". */
    public function test_conditional_rules_are_valid_json_attributes(): void
    {
        $this->actingAs($this->client());

        $html = $this->get(route('alerts.interview', 2))->assertOk()->getContent();
        preg_match('/data-show-if="([^"]*)"/', $html, $showIf);
        preg_match('/data-filter-map="([^"]*)"/', $html, $filterMap);

        $this->assertEquals(['key' => 'uses_framework', 'in' => ['sim']], json_decode(html_entity_decode($showIf[1]), true));
        $this->assertSame([], json_decode(html_entity_decode($filterMap[1]), true));

        $html = $this->get(route('alerts.interview', 3))->getContent();
        preg_match('/data-filter-map="([^"]*)"/', $html, $osMap);
        $this->assertSame(['linux'], json_decode(html_entity_decode($osMap[1]), true)['linux']);
    }

    public function test_answers_reopen_as_they_were(): void
    {
        $this->actingAs($this->client());
        $this->step(2, ['languages' => ['python'], 'uses_framework' => 'sim', 'frameworks' => ['django']]);

        $this->get(route('alerts.interview', 2))
            ->assertOk()
            ->assertSee('value="django" checked', false)
            ->assertDontSee('value="laravel" checked', false);
    }

    public function test_hidden_questions_do_not_count(): void
    {
        $user = $this->client();
        $this->actingAs($user);

        $this->step(2, ['languages' => ['php'], 'uses_framework' => 'sim', 'frameworks' => ['laravel']]);
        $this->step(2, ['languages' => ['php'], 'uses_framework' => 'nao', 'frameworks' => ['laravel']]);

        $this->assertSame(['php'], $user->alertProfile->techs()->pluck('slug')->all());
    }

    public function test_required_and_invalid_answers_are_rejected(): void
    {
        $this->actingAs($this->client());

        $this->step(1, ['company_name' => ''])->assertSessionHasErrors(['answers.company_name' => 'Esta resposta é obrigatória.']);
        $this->step(1, ['company_name' => 'Acme', 'sectors' => ['nao-existe']])->assertSessionHasErrors('answers.sectors');
        $this->step(2, ['languages' => ['<script>']])->assertSessionHasErrors('answers.languages');
        $this->step(6, ['min_criticity' => 'urgent'])->assertSessionHasErrors('answers.min_criticity');
    }

    public function test_finish_sends_back_to_the_missing_required_answer(): void
    {
        $this->actingAs($this->client());
        $this->step(2, ['languages' => ['php']]);

        $this->post(route('alerts.interview.finish'))
            ->assertRedirect(route('alerts.interview', 1))
            ->assertSessionHasErrors('answers.company_name');
    }

    public function test_domain_consent_and_revocation(): void
    {
        $user = $this->client();
        $this->actingAs($user);

        $this->step(5, ['has_domain' => 'sim', 'domain' => 'https://www.AcmePay.com.br/contato'], ['consent' => '1', 'acao' => 'ficar'])
            ->assertRedirect(route('alerts.interview', 5));

        $domain = MonitoredDomain::query()->firstOrFail();
        $this->assertSame('acmepay.com.br', $domain->domain);
        $this->assertNotNull($domain->consent_at);
        $this->assertSame('v3', $domain->consent_version);
        $this->assertSame('127.0.0.1', $domain->consent_ip);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $domain->verify_token);

        $this->get(route('alerts.interview', 5))->assertOk()->assertSee('secmon-verify='.$domain->verify_token);

        // O domínio vira marca: notícia que o citar alerta sempre.
        $this->assertStringContainsString('"acmepay.com.br"', $user->alertProfile->refresh()->compiled_query);

        // Mesmo domínio, sem o aceite: revoga e apaga os achados.
        $domain->findings()->create(['label' => 'Nginx', 'evidence' => 'Server: nginx']);
        $this->step(5, ['has_domain' => 'sim', 'domain' => 'acmepay.com.br']);

        $this->assertNull($domain->refresh()->consent_at);
        $this->assertSame(0, $domain->findings()->count());

        // "Não tenho domínio" apaga o registro.
        $this->step(5, ['has_domain' => 'nao']);
        $this->assertModelMissing($domain);
    }

    public function test_internal_or_ip_domains_are_refused(): void
    {
        $this->actingAs($this->client());

        foreach (['10.0.0.1', 'localhost', 'intranet.local', 'acme.com:8080', 'x', 'user@acme.com'] as $bad) {
            $this->step(5, ['has_domain' => 'sim', 'domain' => $bad], ['consent' => '1'])
                ->assertSessionHasErrors('answers.domain');
        }

        $this->assertDatabaseCount('monitored_domains', 0);
    }

    public function test_pause_and_delete_profile(): void
    {
        $user = $this->client();
        $profile = $this->profile($user, [], ['laravel']);
        app(InterviewService::class)->recompile($profile);
        $this->actingAs($user);

        $this->post(route('alerts.pause'))->assertSessionHas('status');
        $this->assertTrue($profile->refresh()->isPaused());
        $this->assertArrayNotHasKey($profile->id, $this->index->stored);

        $this->post(route('alerts.pause'));
        $this->assertTrue($profile->refresh()->isActive());
        $this->assertArrayHasKey($profile->id, $this->index->stored);

        $this->delete(route('alerts.destroy'))->assertRedirect(route('alerts.news'));
        $this->assertModelMissing($profile);
        $this->assertSame([], $this->index->stored);
    }
}
