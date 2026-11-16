<?php

namespace Tests\Feature\Alerts;

use App\Jobs\ExpandCustomAnswer;
use App\Models\AlertProfile;
use App\Models\CustomAnswerTerm;
use App\Models\InterviewQuestion;
use App\Services\Alerts\CustomAnswerExpander;
use App\Services\Alerts\InterviewService;
use App\Services\Alerts\MatchEngine;
use App\Services\Alerts\ProfileCompiler;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\ProfilePreview;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** "Não achou? Escreva aqui": respostas próprias nas perguntas de escolha. */
class CustomAnswersTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        $this->mock(ProfilePreview::class)->shouldReceive('run')->andReturn([
            'total' => 0, 'by_level' => [], 'per_week' => 0, 'error' => null, 'examples' => [],
        ]);
    }

    private function step1(array $answers)
    {
        return $this->put(route('alerts.interview.update', 1), ['answers' => $answers + ['company_name' => 'Acme Adult']]);
    }

    /** Perfil com resposta escrita já traduzida (ou ainda pendente). */
    private function customProfile(string $question, string $label, array $terms = [], array $codes = [], array $attributes = []): AlertProfile
    {
        CustomAnswerTerm::query()->create([
            'kind' => CustomAnswerTerm::kindFor($question), 'label' => $label, 'normalized' => CustomAnswerTerm::normalize($label),
            'terms' => $terms, 'codes' => $codes, 'status' => $terms || $codes ? 'ready' : 'pending',
        ]);

        return $this->profile(null, $attributes + ['custom_answers' => [$question => [$label]]]);
    }

    public function test_choice_questions_offer_a_written_option(): void
    {
        $this->actingAs($this->client());

        $this->get(route('alerts.interview', 1))->assertOk()
            ->assertSee('name="answers[sectors_outros][]"', false)
            ->assertSee('name="answers[regions_outros][]"', false)
            ->assertDontSee('answers[company_name_outros]', false)
            ->assertSee('Não achou? Escreva aqui');

        $this->get(route('alerts.interview', 2))->assertOk()->assertSee('name="answers[languages_outros][]"', false);
        $this->get(route('alerts.interview', 6))->assertOk()
            ->assertSee('name="answers[attack_types_outros][]"', false)
            ->assertDontSee('answers[min_criticity_outros]', false);
    }

    public function test_written_sector_is_saved_registered_and_sent_to_translation(): void
    {
        Queue::fake();
        $user = $this->client();

        $this->actingAs($user)->step1(['sectors' => ['midia'], 'sectors_outros' => ['Entretenimento adulto', ' entretenimento  ADULTO ', '']])
            ->assertRedirect(route('alerts.interview', 2));

        $profile = AlertProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['sectors' => ['Entretenimento adulto']], $profile->custom_answers);
        $this->assertSame(['midia'], $profile->sectors);

        $row = CustomAnswerTerm::query()->sole();
        $this->assertSame(['sector', 'entretenimento adulto', 'pending'], [$row->kind, $row->normalized, $row->status]);
        Queue::assertPushedOn('alerts', ExpandCustomAnswer::class, fn ($job) => $job->answerId === $row->id);

        // Outro cliente que escreve o mesmo reaproveita a tradução.
        $this->actingAs($this->client())->step1(['sectors_outros' => ['ENTRETENIMENTO ADULTO']]);
        $this->assertSame(1, CustomAnswerTerm::query()->count());
        Queue::assertPushed(ExpandCustomAnswer::class, 1);

        $this->actingAs($user)->get(route('alerts.interview', 7))->assertOk()->assertSee('Mídia e entretenimento, Entretenimento adulto (escrito)');
    }

    public function test_written_answer_alone_satisfies_a_required_question(): void
    {
        Queue::fake();
        InterviewQuestion::query()->where('key', 'sectors')->update(['required' => true]);

        $this->actingAs($this->client())->step1([])->assertSessionHasErrors('answers.sectors');
        $this->actingAs($this->client())->step1(['sectors_outros' => ['Entretenimento adulto']])->assertSessionHasNoErrors();
    }

    public function test_limits_on_written_answers(): void
    {
        $this->actingAs($this->client())->step1(['sectors_outros' => [str_repeat('a', 81)]])->assertSessionHasErrors('answers.sectors');
        $this->actingAs($this->client())->step1(['sectors_outros' => array_map(fn ($i) => "setor {$i}", range(1, 11))])->assertSessionHasErrors('answers.sectors');
    }

    public function test_translated_sector_matches_the_english_classification_of_the_case(): void
    {
        $profile = $this->customProfile('sectors', 'Entretenimento adulto', ['adult entertainment', 'adult content*'], attributes: ['include_sector_news' => true]);
        $case = $this->newsCase('Adult site leak', 'high', ['victim_sectors' => ['Adult Entertainment']]);

        $result = app(MatchEngine::class)->evaluate($profile, $case);

        $this->assertTrue($result->matched());
        $this->assertSame('Entretenimento adulto', $result->reasons[0]['label']);
    }

    public function test_untranslated_answer_still_counts_as_written(): void
    {
        $profile = $this->customProfile('sectors', 'Gaming', attributes: ['include_sector_news' => true]);
        $case = $this->newsCase('Studio breach', 'high', ['victim_sectors' => ['Gaming']]);

        $this->assertTrue(app(MatchEngine::class)->evaluate($profile, $case)->matched());
    }

    public function test_written_technology_is_part_of_the_stack(): void
    {
        $profile = $this->customProfile('tools', 'Protheus', ['totvs protheus', 'totvs']);
        $case = $this->newsCase('TOTVS fixes RCE', 'critical', ['affected_products' => ['TOTVS Protheus 12']]);

        $result = app(MatchEngine::class)->evaluate($profile, $case);

        $this->assertTrue($result->matched());
        $this->assertSame('Protheus', $result->reasons[0]['label']);
        $this->assertStringContainsString('"protheus"', (string) app(ProfileCompiler::class)->matchQuery($profile));
    }

    public function test_written_region_adds_its_countries(): void
    {
        $profile = $this->customProfile('regions', 'Angola e Moçambique', codes: ['AO', 'MZ']);

        $this->assertSame(['AO', 'MZ'], app(ProfileCompiler::class)->terms($profile)['regions']);
    }

    public function test_written_attack_type_filters_like_a_chosen_one(): void
    {
        $profile = $this->customProfile('attack_types', 'Troca de chip', ['sim swap']);
        $profile->terms()->create(['value' => 'Vivo']);
        $engine = app(MatchEngine::class);

        $simSwap = $this->newsCase('Vivo customers hit by SIM swap', 'high', ['affected_products' => ['Vivo'], 'attack_type' => ['sim_swap']]);
        $ransomware = $this->newsCase('Vivo ransomware', 'high', ['affected_products' => ['Vivo'], 'attack_type' => ['ransomware']]);

        $this->assertNull($engine->evaluate($profile->refresh(), $simSwap)->suppressed);
        $this->assertSame('type_filter', $engine->evaluate($profile, $ransomware)->suppressed);
    }

    public function test_job_stores_the_terms_and_recompiles_who_uses_them(): void
    {
        $profile = $this->customProfile('sectors', 'Entretenimento adulto', attributes: ['include_sector_news' => true]);
        $row = CustomAnswerTerm::query()->sole();

        $this->mock(CustomAnswerExpander::class)->shouldReceive('expand')->once()->andReturn(['terms' => ['adult entertainment'], 'codes' => []]);
        app()->call([new ExpandCustomAnswer($row->id), 'handle']);

        $this->assertSame(['ready', ['adult entertainment']], [$row->refresh()->status, $row->terms]);
        // Na sintaxe do Elasticsearch o espaço do termo vai escapado.
        $this->assertStringContainsString('*adult\\ entertainment*', (string) $profile->refresh()->match_query);
    }

    public function test_job_failure_is_recorded_and_admin_edit_wins_over_the_ai(): void
    {
        $this->customProfile('sectors', 'Entretenimento adulto');
        $row = CustomAnswerTerm::query()->sole();

        $this->mock(CustomAnswerExpander::class)->shouldReceive('expand')->andThrow(new RuntimeException('hermes HTTP 503'));
        app()->call([new ExpandCustomAnswer($row->id), 'handle']);
        $this->assertSame('failed', $row->refresh()->status);

        $this->actingAs($this->adminUser())
            ->put(route('admin.alerts.custom.update', $row), ['values' => 'Adult Entertainment, adult content*'])
            ->assertRedirect();

        $this->assertSame(['ready', true, ['adult entertainment', 'adult content*']], [$row->refresh()->status, $row->edited_by_admin, $row->terms]);

        // A IA não sobrescreve a correção do admin.
        app()->call([new ExpandCustomAnswer($row->id), 'handle']);
        $this->assertSame(['adult entertainment', 'adult content*'], $row->refresh()->terms);
    }

    public function test_admin_page_lists_answers_and_who_uses_them(): void
    {
        Queue::fake();
        $this->customProfile('sectors', 'Entretenimento adulto', ['adult entertainment']);
        $row = CustomAnswerTerm::query()->sole();

        $this->actingAs($this->adminUser())->get(route('admin.alerts.custom'))
            ->assertOk()
            ->assertSee('Entretenimento adulto')
            ->assertSee('adult entertainment')
            ->assertSee('1 cliente(s): Acme Pagamentos');

        $this->actingAs($this->adminUser())->post(route('admin.alerts.custom.regenerate', $row))->assertRedirect();
        Queue::assertPushed(ExpandCustomAnswer::class);

        $this->actingAs($this->client())->get(route('admin.alerts.custom'))->assertForbidden();
    }

    /** "Intercom" escrito em Ferramentas: o item do catálogo, com termos curados, e não um termo para a IA traduzir. */
    public function test_written_answer_that_exists_in_the_catalog_becomes_the_catalog_item(): void
    {
        Queue::fake();
        $user = $this->client();

        $this->actingAs($user)->put(route('alerts.interview.update', 4), ['answers' => [
            'tools' => ['slack'], 'tools_outros' => ['Intercom', 'Movidesk', 'RDStation'],
        ]])->assertSessionHasNoErrors();

        $profile = AlertProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertEqualsCanonicalizing(['slack', 'intercom', 'rd-station'], $profile->techs()->pluck('slug')->all());
        $this->assertSame(['interview'], $profile->techs()->pluck('alert_profile_techs.source')->unique()->values()->all());
        $this->assertSame(['tools' => ['Movidesk']], $profile->custom_answers);

        // Só o que não existe no catálogo vai para a tradução.
        $this->assertSame(['movidesk'], CustomAnswerTerm::query()->pluck('normalized')->all());
        Queue::assertPushed(ExpandCustomAnswer::class, 1);

        // RD Station é opção de Ferramentas: vira a marcada. Intercom não é: o escrito continua na tela,
        // mas aparece como o item.
        $this->get(route('alerts.interview', 4))->assertSee('value="rd-station" checked', false)->assertSee('value="Intercom"', false);
        $this->get(route('alerts.interview', 7))->assertOk()
            ->assertSee('Slack, RD Station, Intercom, Movidesk (escrito)')
            ->assertDontSee('Intercom (escrito)');
    }

    /** Escreveu o que estava na lista ("postgres", "Jira"): vira a opção marcada. */
    public function test_written_option_the_question_offers_is_checked(): void
    {
        Queue::fake();
        $user = $this->client();
        $this->actingAs($user);

        $this->put(route('alerts.interview.update', 3), ['answers' => ['databases' => ['redis'], 'databases_outros' => ['postgres']]]);
        $this->put(route('alerts.interview.update', 4), ['answers' => ['tools_outros' => ['Jira']]]);

        $values = app(InterviewService::class)->values(AlertProfile::query()->where('user_id', $user->id)->firstOrFail());
        $this->assertSame(['redis', 'postgresql'], $values['databases']);
        $this->assertSame([], $values['databases_outros']);
        $this->assertSame(['atlassian'], $values['tools']);
        $this->get(route('alerts.interview', 3))->assertSee('value="postgresql" checked', false);
        Queue::assertNothingPushed();
    }

    /** Framework sem a linguagem marcada: a opção ficaria escondida, então o escrito fica (e conta como o item). */
    public function test_framework_written_without_its_language_stays_written(): void
    {
        $user = $this->client();

        $this->actingAs($user)->put(route('alerts.interview.update', 2), ['answers' => [
            'languages' => ['javascript'], 'uses_framework' => 'sim', 'frameworks_outros' => ['Laravel'],
        ]]);

        $profile = AlertProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['Laravel'], app(InterviewService::class)->values($profile)['frameworks_outros']);
        $this->assertContains('laravel', $profile->techs()->pluck('slug')->all());
        $this->assertNull($profile->custom_answers);
    }

    /** Setor, região e tipo de ameaça não são tecnologia: "Slack" escrito em setor continua escrito. */
    public function test_only_technology_questions_look_at_the_catalog(): void
    {
        Queue::fake();
        $user = $this->client();

        $this->actingAs($user)->step1(['sectors_outros' => ['Slack']]);

        $profile = AlertProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['sectors' => ['Slack']], $profile->custom_answers);
        $this->assertSame(0, $profile->techs()->count());
    }

    /** Perfil antigo, de antes da regra: "Recompilar perfil" no admin refaz pelas respostas. */
    public function test_admin_recompile_applies_the_rule_to_old_answers(): void
    {
        Queue::fake();
        $profile = $this->customProfile('tools', 'Intercom', ['intercom', 'intercom messenger']);
        DB::table('alert_profile_answers')->insert([
            'profile_id' => $profile->id, 'question_key' => 'tools_outros', 'value' => json_encode(['Intercom']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->adminUser())->post(route('admin.alerts.recompile'))->assertSessionHas('status', '1 perfil(is) recompilado(s). O percolator foi regravado.');

        $profile->refresh();
        $this->assertNull($profile->custom_answers);
        $this->assertSame(['intercom'], $profile->techs()->pluck('slug')->all());
    }

    public function test_expander_answer_is_cleaned(): void
    {
        $sector = CustomAnswerExpander::parse("```json\n{\"terms\": [\"Adult Entertainment\", \"ad\", \"adult content*\"], \"codes\": [\"BR\"]}\n```", 'sector');
        $this->assertSame(['terms' => ['adult entertainment', 'adult content*'], 'codes' => []], $sector);

        $region = CustomAnswerExpander::parse('{"terms": ["angola"], "codes": ["ao", "MZ", "XYZ"]}', 'region');
        $this->assertSame(['terms' => [], 'codes' => ['AO', 'MZ']], $region);

        $this->expectException(RuntimeException::class);
        CustomAnswerExpander::parse('{"terms": []}', 'tech');
    }

    public function test_the_translation_is_a_light_call_and_an_empty_setting_sends_no_effort(): void
    {
        Settings::setMany(['enricher.hermes_url' => 'http://hermes.test']);
        Http::fake(['hermes.test/*' => Http::response(['choices' => [['message' => ['content' => '{"terms": ["adult entertainment"], "codes": []}']]]])]);
        $answer = new CustomAnswerTerm(['kind' => 'sector', 'label' => 'Entretenimento adulto']);

        app(CustomAnswerExpander::class)->expand($answer);
        Http::assertSent(fn (Request $request) => $request['reasoning_effort'] === 'low');

        // Vazio: vale o padrão do hermes, sem o campo.
        config(['secmon.enricher.hermes.light_effort' => '']);
        Http::fake(['hermes.test/*' => Http::response(['choices' => [['message' => ['content' => '{"terms": ["adult entertainment"], "codes": []}']]]])]);

        app(CustomAnswerExpander::class)->expand($answer);
        Http::assertSent(fn (Request $request) => ! isset($request['reasoning_effort']));
    }
}
