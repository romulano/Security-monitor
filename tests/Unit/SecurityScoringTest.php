<?php

namespace Tests\Unit;

use App\Services\Security\CheckCatalog;
use App\Services\Security\CheckResult;
use App\Services\Security\ScanReport;
use PHPUnit\Framework\TestCase;

/** A nota (100 menos os pontos perdidos) e o estado do site. */
class SecurityScoringTest extends TestCase
{
    public function test_a_critical_loses_the_whole_weight_and_a_warning_half(): void
    {
        $this->assertSame(25, CheckCatalog::penalty('tls.expiry', 'critico'));
        $this->assertSame(13, CheckCatalog::penalty('tls.expiry', 'aviso'));
        $this->assertSame(0, CheckCatalog::penalty('tls.expiry', 'ok'));
        $this->assertSame(0, CheckCatalog::penalty('tls.expiry', 'nao_testavel'));
        $this->assertSame(0, CheckCatalog::penalty('nao.existe', 'critico'));
    }

    public function test_every_check_id_is_in_the_catalog_with_a_label_a_group_and_advice(): void
    {
        foreach (CheckCatalog::all() as $id => $info) {
            $this->assertNotSame('', $info['label'], $id);
            $this->assertArrayHasKey($info['group'], CheckCatalog::GROUPS, $id);
            $this->assertNotSame('', $info['advice'], $id);
            $this->assertGreaterThan(0, $info['weight'], $id);
        }
    }

    public function test_the_report_takes_the_worst_state_and_subtracts_the_points(): void
    {
        $report = ScanReport::build([
            CheckResult::ok('tls.valid', 'ok'),
            CheckResult::warning('http.hsts', 'ausente'),
            CheckResult::critical('http.redirect', 'sem redirecionar'),
            CheckResult::untested('dom.expiry', 'sem data'),
        ], 200, null, 1200, reachable: true);

        $this->assertSame('critico', $report->status);
        $this->assertSame(100 - 4 - 15, $report->score);
        $this->assertCount(2, $report->problems());
    }

    public function test_a_clean_scan_scores_100_and_the_score_never_goes_below_zero(): void
    {
        $clean = ScanReport::build([CheckResult::ok('tls.valid')], 200, null, 10, true);
        $this->assertSame([100, 'ok'], [$clean->score, $clean->status]);

        $terrible = ScanReport::build([
            CheckResult::critical('tls.valid', 'x'), CheckResult::critical('tls.expiry', 'x'), CheckResult::critical('http.redirect', 'x'),
            CheckResult::critical('file.exposed', 'x', '/.env'), CheckResult::critical('dom.expiry', 'x'),
        ], 200, null, 10, true);
        $this->assertSame(0, $terrible->score);
    }

    public function test_a_site_that_never_answered_is_an_error_with_no_score(): void
    {
        $report = ScanReport::build([CheckResult::untested('tls.valid')], null, null, 10, reachable: false);

        $this->assertSame('erro', $report->status);
        $this->assertSame(0, $report->score);
        $this->assertSame('o site não respondeu', $report->error);
    }

    public function test_the_fingerprint_follows_the_states_and_ignores_the_short_values(): void
    {
        $a = ScanReport::build([CheckResult::ok('tls.expiry', 'vence em 40 dias'), CheckResult::warning('http.hsts', 'ausente')], 200, null, 10, true);
        $sameStates = ScanReport::build([CheckResult::warning('http.hsts', 'ausente de novo'), CheckResult::ok('tls.expiry', 'vence em 39 dias')], 200, null, 99, true);
        $other = ScanReport::build([CheckResult::ok('tls.expiry', 'x'), CheckResult::ok('http.hsts', 'x')], 200, null, 10, true);

        $this->assertSame($a->fingerprint(), $sameStates->fingerprint());
        $this->assertNotSame($a->fingerprint(), $other->fingerprint());
    }

    public function test_results_are_stored_as_state_and_a_short_value_keyed_by_check_and_subject(): void
    {
        $report = ScanReport::build([
            CheckResult::warning('cookie.flags', 'falta Secure', 'sessao'),
            CheckResult::ok('tls.valid', str_repeat('x', 500)),
        ], 200, null, 10, true);

        $stored = $report->toResults();

        $this->assertSame(['s' => 'aviso', 'v' => 'falta Secure'], $stored['cookie.flags|sessao']);
        $this->assertSame(120, mb_strlen($stored['tls.valid|']['v']), 'valor curto: nunca o conteúdo');
    }

    public function test_the_subject_fits_the_findings_column(): void
    {
        $this->assertSame(120, mb_strlen((new CheckResult('cookie.flags', 'aviso', 'x', str_repeat('a', 300)))->subject));
    }
}
