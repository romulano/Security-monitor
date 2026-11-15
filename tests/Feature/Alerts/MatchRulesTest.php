<?php

namespace Tests\Feature\Alerts;

use App\Services\Alerts\MatchEngine;
use App\Services\Alerts\ProfileCompiler;
use App\Services\Alerts\TermMatcher;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Etapa 2 (núcleo): como o perfil vira termos, queries e decisões. */
class MatchRulesTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    public function test_terms_match_whole_words_without_case_or_accents(): void
    {
        $this->assertTrue(TermMatcher::contains('Falha no Laravel permite RCE', 'laravel'));
        $this->assertTrue(TermMatcher::contains('Next.js middleware bypass', 'next.js'));
        $this->assertTrue(TermMatcher::contains('Notícia sobre SAÚDE', 'saude'));
        $this->assertTrue(TermMatcher::contains('financial services', 'financ*'));

        // "php" não pode casar dentro de outra palavra, nem "java" em "javascript".
        $this->assertFalse(TermMatcher::contains('phpMyAdmin exposed', 'php'));
        $this->assertFalse(TermMatcher::contains('malicious javascript loader', 'java'));
        $this->assertFalse(TermMatcher::contains('anything', '  '));
    }

    public function test_compiler_builds_feed_compatible_and_percolator_queries(): void
    {
        $profile = $this->profile(null, [
            'brands' => ['Acme Pay'],
            'sectors' => ['financeiro'],
            'include_sector_news' => true,
        ], ['laravel', 'fortinet'], ['Totvs "Protheus"']);
        $profile->domains()->create(['domain' => 'acmepay.com.br', 'verify_token' => 'x']);
        $profile->refresh();

        $compiler = app(ProfileCompiler::class);
        $compiled = $compiler->compiledQuery($profile);
        $match = $compiler->matchQuery($profile);

        $this->assertStringContainsString('criticity:(critical OR high)', $compiled);
        $this->assertStringContainsString('"laravel"', $compiled);
        $this->assertStringContainsString('"fortios"', $compiled);
        $this->assertStringContainsString('"totvs \"protheus\""', $compiled);
        $this->assertStringContainsString('victim_sectors:(financ*', $compiled);
        $this->assertStringContainsString('"acmepay.com.br"', $compiled);
        $this->assertStringContainsString('"acme pagamentos"', $compiled);

        // O percolator não filtra nível e cerca o setor de curingas.
        $this->assertStringNotContainsString('criticity', $match);
        $this->assertStringContainsString('victim_sectors:(*financ*', $match);
        $this->assertStringContainsString('*law\ firm*', $compiler->matchQuery($profile->fill(['sectors' => ['juridico']])));
    }

    public function test_profile_without_anything_to_look_for_has_no_query(): void
    {
        $profile = $this->profile(null, ['company_name' => null, 'include_brand_mentions' => false]);

        $this->assertNull(app(ProfileCompiler::class)->matchQuery($profile));
        $this->assertNull(app(ProfileCompiler::class)->compiledQuery($profile));
    }

    public function test_stack_reasons_score_and_cve_bonus(): void
    {
        $profile = $this->profile(null, [], ['laravel', 'nginx']);
        $case = $this->newsCase('Laravel framework flaw', 'high', [
            'affected_products' => ['Laravel Framework 11.x'],
            'cves' => ['CVE-2026-1111'],
        ], ['summary' => 'Servers running nginx are also affected.']);

        $result = app(MatchEngine::class)->evaluate($profile, $case);

        $this->assertTrue($result->matched());
        $this->assertNull($result->suppressed);
        $this->assertSame(80, $result->score); // 60 da stack + 20 do CVE
        $types = collect($result->reasons)->pluck('type', 'label')->all();
        $this->assertSame('stack', $types['Laravel']);
        $this->assertSame('mention', $types['Nginx']);
        $this->assertSame('cve', $types['CVE-2026-1111']);
    }

    public function test_broad_technologies_only_count_as_affected_products(): void
    {
        $engine = app(MatchEngine::class);
        $profile = $this->profile(null, [], ['python', 'windows']);

        // "Python-based malware" não afeta quem usa Python.
        $mention = $this->newsCase('New Python-based stealer targets Windows users', 'high', ['malware_families' => ['PyStealer']]);
        $this->assertFalse($engine->evaluate($profile, $mention)->matched());

        $product = $this->newsCase('Windows zero-day patched', 'critical', ['affected_products' => ['Microsoft Windows 11']]);
        $result = $engine->evaluate($profile, $product);
        $this->assertTrue($result->matched());
        $this->assertSame('stack', $result->reasons[0]['type']);

        $compiler = app(ProfileCompiler::class);
        $this->assertStringContainsString('"python"', $compiler->matchQuery($profile), 'percolator continua superconjunto');
        $this->assertStringContainsString('affected_products:(python*', $compiler->compiledQuery($profile));
    }

    public function test_brand_mentions_ignore_level_and_types(): void
    {
        $profile = $this->profile(null, ['brands' => ['Acme Pay'], 'min_criticity' => 'critical', 'attack_types' => ['ransomware']]);
        $case = $this->newsCase('Phishing campaign', 'low', ['victim_orgs' => ['Acme Pay'], 'attack_type' => ['phishing']]);

        $result = app(MatchEngine::class)->evaluate($profile, $case);

        $this->assertTrue($result->isBrand());
        $this->assertSame(100, $result->score);
        $this->assertNull($result->suppressed);
    }

    public function test_brand_mentions_can_be_turned_off(): void
    {
        $profile = $this->profile(null, ['brands' => ['Acme Pay'], 'include_brand_mentions' => false]);
        $case = $this->newsCase('Breach', 'critical', ['victim_orgs' => ['Acme Pay']]);

        $this->assertFalse(app(MatchEngine::class)->evaluate($profile, $case)->matched());
    }

    public function test_below_level_type_filter_and_low_score_are_suppressed(): void
    {
        $engine = app(MatchEngine::class);
        $profile = $this->profile(null, ['min_criticity' => 'high'], ['redis']);

        $low = $this->newsCase('Redis minor bug', 'medium', ['affected_products' => ['Redis']]);
        $this->assertSame('below_level', $engine->evaluate($profile, $low)->suppressed);

        $profile->update(['attack_types' => ['ransomware']]);
        $phishing = $this->newsCase('Redis phishing lure', 'high', ['affected_products' => ['Redis'], 'attack_type' => ['phishing']]);
        $this->assertSame('type_filter', $engine->evaluate($profile->refresh(), $phishing)->suppressed);

        // Caso sem classificação passa no filtro de tipos.
        $unclassified = $this->newsCase('Redis issue', 'high', ['affected_products' => ['Redis']]);
        $this->assertNull($engine->evaluate($profile, $unclassified)->suppressed);

        // "Vulnerabilidades" aceita qualquer caso com CVE.
        $profile->update(['attack_types' => ['vulnerabilidades']]);
        $withCve = $this->newsCase('Redis RCE', 'high', ['affected_products' => ['Redis'], 'attack_type' => ['malware'], 'cves' => ['CVE-2026-2']]);
        $this->assertNull($engine->evaluate($profile->refresh(), $withCve)->suppressed);

        Settings::set('alerts.min_score', 70);
        $this->assertSame('low_score', $engine->evaluate($profile, $unclassified)->suppressed);
    }

    public function test_sector_only_counts_when_the_client_asked_for_it(): void
    {
        $engine = app(MatchEngine::class);
        $profile = $this->profile(null, ['sectors' => ['saude'], 'include_sector_news' => false]);
        $case = $this->newsCase('Hospital ransomware', 'critical', ['victim_sectors' => ['Healthcare']]);

        $this->assertFalse($engine->evaluate($profile, $case)->matched());

        $profile->update(['include_sector_news' => true]);
        $result = $engine->evaluate($profile->refresh(), $case);

        $this->assertTrue($result->matched());
        $this->assertSame('Saúde', $result->reasons[0]['label']);
    }

    public function test_region_bonus(): void
    {
        $profile = $this->profile(null, ['regions' => ['brasil']], ['fortinet']);
        $case = $this->newsCase('FortiOS flaw exploited in Brazil', 'critical', ['vendors' => ['Fortinet'], 'countries_code' => ['BR']]);

        $this->assertSame(70, app(MatchEngine::class)->evaluate($profile, $case)->score);
    }
}
