<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Enrichment\ClaudeEnricher;
use App\Services\Search\CaseIndex;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModelSelectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CaseIndex::class)->shouldIgnoreMissing();

        config([
            'secmon.enricher.claude.api_key' => null,
            'secmon.enricher.openai.api_key' => null,
            'secmon.enricher.openai.base_url' => 'https://api.openai.com/v1',
            'secmon.enricher.hermes.token' => 'segredo',
        ]);

        Settings::flush();
        Settings::setMany([
            'enricher.hermes_url' => 'http://hermes.test:8765',
            'enricher.claude_model' => 'claude-opus-5',
            'enricher.openai_model' => 'gpt-4o-mini',
            'enricher.hermes_model' => 'sonnet',
        ]);
    }

    private function hermesOnline(): void
    {
        Http::fake([
            'hermes.test:8765/healthz' => Http::response(['servico' => 'hermes', 'modelo' => 'sonnet']),
            'hermes.test:8765/v1/chat/completions' => Http::response([], 400),
            'hermes.test:8765/v1/models' => Http::response(['object' => 'list', 'data' => [
                ['id' => 'claude-code'], ['id' => 'opus'], ['id' => 'sonnet'], ['id' => 'haiku'],
            ]]),
            'api.openai.test/*' => Http::response(['data' => [['id' => 'gpt-b'], ['id' => 'gpt-a']]]),
        ]);
    }

    private function settingsPage()
    {
        return $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.settings'));
    }

    public function test_model_fields_are_selects_with_the_saved_model_selected(): void
    {
        $this->hermesOnline();

        $this->settingsPage()
            ->assertOk()
            ->assertSee('<select name="enricher_claude_model"', false)
            ->assertSee('<select name="enricher_openai_model"', false)
            ->assertSee('<select name="enricher_hermes_model"', false)
            ->assertDontSee('<input type="text" name="enricher_claude_model"', false)
            // Claude sem chave: lista fixa.
            ->assertSee('value="claude-opus-5" selected', false)
            ->assertSee('value="claude-opus-5-5"', false)
            ->assertSee('Lista fixa. Com ANTHROPIC_API_KEY')
            // OpenAI sem chave: só o modelo em uso, com o aviso.
            ->assertSee('value="gpt-4o-mini" selected', false)
            ->assertSee('Sem lista do provedor');
    }

    public function test_hermes_select_offers_default_aliases_and_full_names(): void
    {
        $this->hermesOnline();

        $this->settingsPage()
            ->assertSee('<option value="" >o do hermes', false)
            ->assertSee('value="sonnet" selected', false)
            ->assertSee('value="opus"', false)
            ->assertSee('value="fable"', false)
            ->assertSee('value="claude-fable-5-1"', false)
            ->assertSee('Aliases informados pelo hermes.')
            // `claude-code` é o padrão do hermes, já coberto pela opção vazia.
            ->assertDontSee('value="claude-code"', false);
    }

    public function test_hermes_offline_falls_back_to_the_fixed_aliases(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->settingsPage()
            ->assertOk()
            ->assertSee('hermes fora do ar: aliases da lista fixa.')
            ->assertSee('value="haiku"', false);
    }

    public function test_openai_list_comes_from_the_provider_when_reachable(): void
    {
        config(['secmon.enricher.openai.api_key' => 'sk-test', 'secmon.enricher.openai.base_url' => 'https://api.openai.test/v1']);
        $this->hermesOnline();

        $this->settingsPage()
            ->assertSeeInOrder(['value="gpt-a"', 'value="gpt-b"'], false)
            ->assertSee('Lista do /models de api.openai.test.')
            // O modelo salvo não está na lista do provedor e continua selecionável.
            ->assertSee('<optgroup label="Em uso">', false)
            ->assertSee('value="gpt-4o-mini" selected', false);
    }

    public function test_the_openrouter_list_comes_grouped_by_vendor_and_falls_back_to_the_fixed_one(): void
    {
        config(['secmon.enricher.openrouter.base_url' => 'https://openrouter.test/api/v1']);
        Settings::set('enricher.openrouter_model', 'openai/gpt-4o-mini');
        $this->hermesOnline();
        Http::fake(['openrouter.test/api/v1/models' => Http::response(['data' => [
            ['id' => 'openai/gpt-4o-mini', 'name' => 'OpenAI: GPT-4o-mini'],
            ['id' => 'anthropic/claude-sonnet-4.5', 'name' => 'Anthropic: Claude Sonnet 4.5'],
        ]])]);

        $this->settingsPage()
            ->assertSee('Modelo OpenRouter')
            ->assertSee('OpenRouter')
            ->assertSeeInOrder(['<optgroup label="anthropic">', 'Anthropic: Claude Sonnet 4.5 · anthropic/claude-sonnet-4.5', '<optgroup label="openai">'], false)
            ->assertSee('value="openai/gpt-4o-mini" selected', false)
            ->assertSee('Lista do /models do OpenRouter (2 modelos).');
    }

    public function test_the_openrouter_list_falls_back_to_the_fixed_one_when_it_does_not_answer(): void
    {
        config(['secmon.enricher.openrouter.base_url' => 'https://openrouter.test/api/v1']);
        Http::fake(['openrouter.test/*' => Http::response([], 503), '*' => Http::response([], 404)]);

        $this->settingsPage()->assertSee('<optgroup label="google">', false)->assertSee('OPENROUTER_API_KEY');
    }

    public function test_saving_a_selected_model_and_rejecting_garbage(): void
    {
        $this->hermesOnline();
        $admin = User::factory()->create(['is_admin' => true]);

        $payload = [
            'crawler_enabled' => 1,
            'crawler_max_items' => 25,
            'crawler_timeout' => 20,
            'crawler_dedup_threshold' => 0.62,
            'crawler_user_agent' => 'SecurityMonitorBot/1.0',
            'enricher_enabled' => 1,
            'enricher_driver' => 'hermes',
            'enricher_claude_model' => 'claude-sonnet-5-5',
            'enricher_claude_effort' => 'high',
            'enricher_openai_model' => 'gpt-4o-mini',
            'enricher_openrouter_model' => 'meta-llama/llama-3.3-70b-instruct:free',
            'enricher_hermes_url' => 'http://hermes.test:8765',
            'enricher_hermes_model' => 'claude-fable-5-1',
            'enricher_hermes_effort' => 'low',
        ];

        $this->actingAs($admin)->put(route('admin.settings.update'), $payload)->assertSessionHasNoErrors();

        Settings::flush();
        $this->assertSame('claude-sonnet-5-5', Settings::get('enricher.claude_model'));
        $this->assertSame('high', Settings::get('enricher.claude_effort'));
        $this->assertSame('low', Settings::get('enricher.hermes_effort'), 'o esforço do hermes é outro campo');
        $this->assertSame('claude-fable-5-1', Settings::get('enricher.hermes_model'));
        $this->assertSame('meta-llama/llama-3.3-70b-instruct:free', Settings::get('enricher.openrouter_model'));

        $this->actingAs($admin)->put(route('admin.settings.update'), ['enricher_driver' => 'openrouter'] + $payload)->assertSessionHasNoErrors();
        Settings::flush();
        $this->assertSame('openrouter', Settings::get('enricher.driver'));

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), ['enricher_claude_model' => '<script>x</script>'] + $payload)
            ->assertSessionHasErrors('enricher_claude_model');

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), ['enricher_claude_effort' => 'turbo'] + $payload)
            ->assertSessionHasErrors('enricher_claude_effort');
    }

    public function test_the_claude_effort_goes_in_the_call_and_empty_leaves_the_model_default(): void
    {
        $this->assertSame('low', (new ClaudeEnricher)->outputConfig()['effort'], 'sem nada salvo vale o do config');

        Settings::setMany(['enricher.claude_effort' => 'xhigh']);
        $this->assertSame('xhigh', (new ClaudeEnricher)->outputConfig()['effort']);
        $this->assertSame('json_schema', (new ClaudeEnricher)->outputConfig()['format']['type']);

        Settings::setMany(['enricher.claude_effort' => '']);
        $this->assertArrayNotHasKey('effort', (new ClaudeEnricher)->outputConfig());
    }

    public function test_the_page_shows_the_claude_effort(): void
    {
        Settings::setMany(['enricher.claude_effort' => 'medium']);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('<select name="enricher_claude_effort"', false)
            ->assertSee('<option value="medium" selected', false)
            ->assertSee('<select name="enricher_hermes_effort"', false);
    }
}
