<?php

namespace Tests\Feature;

use App\Jobs\ExpandCustomAnswer;
use App\Models\Article;
use App\Models\CustomAnswerTerm;
use App\Models\NewsCase;
use App\Models\PromptRevision;
use App\Models\User;
use App\Services\Alerts\AlertReviewer;
use App\Services\Alerts\CustomAnswerExpander;
use App\Services\Enrichment\EnricherManager;
use App\Services\Enrichment\EnrichmentPrompt;
use App\Services\Exploits\ExploitExplainer;
use App\Services\Lookalike\LookalikeReviewer;
use App\Services\Merge\MergeEditor;
use App\Services\Prompts\PromptCatalog;
use App\Services\Search\CaseIndex;
use App\Services\Vulnerability\VulnerabilityExplainer;
use App\Support\Prompts;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Aba Prompts do admin: ver e editar o texto que o hermes recebe em cada etapa. */
class AdminPromptsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
        Prompts::forget();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'name' => 'Rômulo']);
    }

    /** Uma matéria com duas fontes, para a prévia ter dados reais. */
    private function story(): NewsCase
    {
        $case = NewsCase::query()->create([
            'title' => 'PHP2Shell: two critical PHP RCE flaws',
            'summary' => 'Consolidated report about php2shell.',
            'first_at' => now()->subHour(),
            'latest_at' => now(),
            'consolidated_at' => now(),
        ]);

        foreach (['bleepingcomputer.com', 'security.paloaltonetworks.com'] as $i => $host) {
            Article::query()->create([
                'case_id' => $case->id,
                'url' => "https://{$host}/php2shell",
                'url_hash' => hash('sha256', $host),
                'source_host' => $host,
                'original_title' => "PHP2Shell on {$host}",
                'content' => "Article text from {$host}.",
                'summary' => "Analysis from {$host}.",
                'status' => 'enriched',
                'is_first' => $i === 0,
                'collected_at' => now()->subMinutes(30 - $i),
            ]);
        }

        return $case;
    }

    public function test_every_stage_shows_its_text_the_fixed_part_and_what_the_ai_receives(): void
    {
        $this->story();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.prompts.index'))
            ->assertOk()
            ->assertSee('Prompts do hermes')
            ->assertSeeInOrder(['Coleta e análise', 'Análise de cada artigo', 'Explicações da matéria', 'Fusão de matérias', 'Enriquecer a consolidada', 'Alertas', 'Tradução das respostas escritas', 'Radar de domínios']);

        foreach (array_keys(PromptCatalog::STAGES) as $slug) {
            $this->actingAs($admin)->get(route('admin.prompts.show', $slug))->assertOk()->assertSee('O que o hermes recebe');
        }

        $this->actingAs($admin)->get(route('admin.prompts.show', 'analise'))
            ->assertSee('Quando o artigo cai numa matéria existente')
            ->assertSee('never rewrite it from')
            // A parte fixa: o schema que o normalizador lê.
            ->assertSee('It must validate against this JSON Schema')
            // A prévia sai do banco: o artigo da Palo Alto entrou na matéria do php2shell.
            ->assertSee('security.paloaltonetworks.com')
            ->assertSee('Consolidated report about php2shell.');

        $this->actingAs($admin)->get(route('admin.prompts.show', 'fusao-enriquecer'))
            ->assertSee('Do not rewrite it from scratch')
            ->assertSee('Analysis from security.paloaltonetworks.com.');
    }

    public function test_an_edited_prompt_is_what_the_ai_receives_and_keeps_the_fixed_part(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.prompts.update', 'merge.write'), ['body' => "Write ONE short record.\r\nKeep it brief."])
            ->assertRedirect(route('admin.prompts.show', 'fusao-escrever').'#merge.write')
            ->assertSessionHas('status', 'Prompt salvo. A próxima chamada do hermes já usa este texto.');

        $system = MergeEditor::writeSystem();
        $this->assertStringStartsWith("Write ONE short record.\nKeep it brief.\n\n", $system);
        $this->assertStringContainsString('<source-CODE>', $system);
        $this->assertStringContainsString('"summary_pt"', $system);
        $this->assertStringNotContainsString('It must carry more detail than any single source', $system);

        $revision = PromptRevision::query()->sole();
        $this->assertSame($admin->id, $revision->user_id);
        $this->assertSame(Prompts::defaultHash('merge.write'), $revision->base_hash);

        $this->actingAs($admin)->get(route('admin.prompts.index'))->assertSee('editado em')->assertSee('por Rômulo');

        // As outras etapas seguem no padrão.
        $this->assertSame(Prompts::default('enrichment.system'), EnrichmentPrompt::system());
    }

    public function test_back_to_the_default_and_to_any_earlier_version(): void
    {
        $admin = $this->admin();
        $put = fn (string $body) => $this->actingAs($admin)->put(route('admin.prompts.update', 'alerts.judge'), ['body' => $body]);

        $put('Judge version A.');
        $put('Judge version B.');
        $this->assertStringStartsWith('Judge version B.', AlertReviewer::system('English'));
        // A regra da nota e o formato da resposta são fixos (a etiqueta com o marcador CODE).
        $this->assertStringContainsString('<note-CODE>', AlertReviewer::system('English'));

        $this->actingAs($admin)->post(route('admin.prompts.reset', 'alerts.judge'))->assertSessionHas('status', 'Trecho de volta ao texto padrão.');
        $this->assertSame(Prompts::default('alerts.judge'), Prompts::text('alerts.judge'));

        $versionA = PromptRevision::query()->where('body', 'Judge version A.')->sole();
        $this->actingAs($admin)->post(route('admin.prompts.restore', $versionA))->assertSessionHas('status');
        $this->assertSame('Judge version A.', Prompts::text('alerts.judge'));

        $this->assertSame(4, PromptRevision::query()->count());
        $this->actingAs($admin)->get(route('admin.prompts.show', 'juiz-alertas'))
            ->assertSee('Histórico (4)')
            ->assertSee('voltou ao padrão')
            ->assertSee('Voltar para esta versão');
    }

    public function test_saving_the_default_text_follows_the_default_again(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.prompts.update', 'exploits.explain'), ['body' => 'Explain it.']);
        $this->actingAs($admin)->put(route('admin.prompts.update', 'exploits.explain'), ['body' => Prompts::default('exploits.explain')]);

        $this->assertNull(PromptRevision::query()->latest('id')->first()->body);

        $this->actingAs($admin)->put(route('admin.prompts.update', 'exploits.explain'), ['body' => Prompts::default('exploits.explain')])
            ->assertSessionHas('status', 'Nada mudou no texto.');
        $this->assertSame(2, PromptRevision::query()->count());
    }

    public function test_the_client_answer_needs_its_place_in_the_text(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->from(route('admin.prompts.show', 'respostas-escritas'))
            ->put(route('admin.prompts.update', 'custom.tech'), ['part' => 'custom.tech', 'body' => 'Return search terms for the product.'])
            ->assertRedirect(route('admin.prompts.show', 'respostas-escritas'))
            ->assertSessionHasErrors(['body' => 'O texto precisa ter {label}: é onde entra a resposta que o cliente escreveu.'])
            // O texto digitado volta para o campo.
            ->assertSessionHasInput('body', 'Return search terms for the product.');

        $this->assertSame(0, PromptRevision::query()->count());

        $this->actingAs($admin)->put(route('admin.prompts.update', 'custom.tech'), ['body' => 'Return search terms for "{label}".']);
        $this->assertSame('Return search terms for "SAP \'S/4\'".', CustomAnswerExpander::task('tech', 'SAP "S/4"'));
    }

    public function test_warns_when_an_update_changed_the_default_after_the_edit(): void
    {
        PromptRevision::query()->create(['key' => 'merge.enrich', 'body' => 'Old edit.', 'base_hash' => sha1('older default')]);

        $this->actingAs($this->admin())->get(route('admin.prompts.show', 'fusao-enriquecer'))
            ->assertSee('o padrão mudou depois')
            ->assertSee('O texto padrão mudou numa atualização do Security Monitor')
            ->assertSee('Ver o texto padrão');
    }

    public function test_the_filter_shows_one_group(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.prompts.index', ['grupo' => 'fusao']))
            ->assertOk()
            ->assertSee('Agrupar matérias')
            ->assertSee('Escrever do zero')
            ->assertDontSee('Juiz de alertas')
            ->assertDontSee('Análise de cada artigo');

        // Grupo desconhecido mostra todos.
        $this->actingAs($admin)->get(route('admin.prompts.index', ['grupo' => 'inventado']))->assertOk()->assertSee('Juiz de alertas');

        $this->actingAs($admin)->get(route('admin.prompts.show', 'fusao-agrupar'))
            ->assertSee(route('admin.prompts.index', ['grupo' => 'fusao']), false);
    }

    public function test_a_stage_turned_off_is_skipped(): void
    {
        $admin = $this->admin();
        config(['secmon.exploits.explain_enabled' => true, 'secmon.vulnerability.explain_enabled' => true, 'secmon.lookalikes.ai_review_enabled' => true]);
        Settings::setMany(['enricher.hermes_url' => 'http://hermes.test']);
        $this->assertTrue(app(ExploitExplainer::class)->enabled());

        $this->actingAs($admin)
            ->from(route('admin.prompts.index'))
            ->post(route('admin.prompts.toggle', 'exploits'))
            ->assertRedirect(route('admin.prompts.index'))
            ->assertSessionHas('status', 'Explicação de exploit: desligada. Exploits novos ficam sem explicação: a matéria mostra só os dados do Exploit-DB.');

        $this->assertFalse(app(ExploitExplainer::class)->enabled());
        $this->actingAs($admin)->get(route('admin.prompts.show', 'exploits'))->assertSee('Etapa desligada: é pulada e a IA não é chamada.');

        $this->actingAs($admin)->post(route('admin.prompts.toggle', 'exploits'));
        $this->assertTrue(app(ExploitExplainer::class)->enabled());

        foreach (['vulnerabilidade' => VulnerabilityExplainer::class, 'juiz-radar' => LookalikeReviewer::class] as $stage => $service) {
            $this->assertTrue(app($service)->enabled(), $stage);
            $this->actingAs($admin)->post(route('admin.prompts.toggle', $stage));
            $this->assertFalse(app($service)->enabled(), $stage);
        }

        // Respostas escritas: a resposta fica pendente e vale como termo do jeito que veio.
        $answer = CustomAnswerTerm::query()->create(['kind' => 'tech', 'label' => 'SAP S/4HANA', 'normalized' => 'sap s/4hana']);
        $this->actingAs($admin)->post(route('admin.prompts.toggle', 'respostas-escritas'));
        Http::fake();
        ExpandCustomAnswer::dispatchSync($answer->id);
        Http::assertNothingSent();
        $this->assertSame('pending', $answer->refresh()->status);
    }

    public function test_the_analysis_and_the_alert_judge_share_the_switch_of_their_settings(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.prompts.toggle', 'analise'));
        $this->assertFalse(Settings::bool('enricher.enabled'));

        $article = Article::query()->create(['url' => 'https://example.com/a', 'url_hash' => hash('sha256', 'a'), 'source_host' => 'example.com', 'original_title' => 'Acme patches CVE-2026-1234', 'content' => 'Acme fixed CVE-2026-1234.', 'status' => 'pending']);
        [$result] = app(EnricherManager::class)->enrich($article);
        $this->assertSame('heuristic', $result->driver);

        // O parecer da IA nos alertas começa desligado nos testes (ALERTS_AI_REVIEW=false).
        $this->assertFalse(Settings::bool('alerts.ai_review_enabled'));
        $this->actingAs($admin)->post(route('admin.prompts.toggle', 'juiz-alertas'))->assertSessionHas('status', fn ($status) => str_contains($status, 'Juiz de alertas: ligada.'));
        $this->assertTrue(Settings::bool('alerts.ai_review_enabled'));
    }

    public function test_only_admins_and_only_known_stages_and_parts(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.prompts.index'))->assertForbidden();
        $this->actingAs(User::factory()->create())->put('/admin/prompts/trechos/merge.write', ['body' => 'x'])->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/prompts/inventada')->assertNotFound();
        $this->actingAs($admin)->post('/admin/prompts/inventada/alternar')->assertNotFound();
        $this->actingAs(User::factory()->create())->post(route('admin.prompts.toggle', 'exploits'))->assertForbidden();
        // O ponto do nome do trecho não é curinga.
        $this->actingAs($admin)->put('/admin/prompts/trechos/mergeXwrite', ['body' => 'x'])->assertNotFound();
        $this->assertSame(0, PromptRevision::query()->count());
    }
}
