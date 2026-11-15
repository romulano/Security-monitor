<?php

namespace Tests\Feature\Security;

use App\Models\SecurityCheck;
use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Services\Security\CheckResult;
use App\Services\Security\ScanRecorder;
use App\Services\Security\ScanReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Gravar a varredura: o histórico, o estado de cada problema e só as mudanças de estado como eventos. */
class ScanRecorderTest extends TestCase
{
    use RefreshDatabase, SecurityTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSecurity();
    }

    private function report(array $results, bool $reachable = true): ScanReport
    {
        return ScanReport::build($results, 200, null, 800, $reachable);
    }

    private function record($site, array $results, bool $reachable = true): SecurityCheck
    {
        return app(ScanRecorder::class)->record($site, $this->report($results, $reachable));
    }

    /** @return list<string> */
    private function kinds($site): array
    {
        return SecurityEvent::query()->where('site_id', $site->id)->orderBy('id')->pluck('kind')->all();
    }

    public function test_the_first_scan_stores_the_check_the_open_problems_and_one_event_each(): void
    {
        $site = $this->site();

        $check = $this->record($site, [
            CheckResult::ok('tls.valid', 'emitido por Let\'s Encrypt'),
            CheckResult::warning('http.hsts', 'ausente'),
            CheckResult::critical('file.exposed', '.env acessível', '/.env'),
        ]);

        $this->assertSame('critico', $check->status);
        $this->assertSame(100 - 4 - 25, $check->score);
        $this->assertSame(['s' => 'aviso', 'v' => 'ausente'], $check->results['http.hsts|']);
        $this->assertSame(40, strlen($check->fingerprint));

        $open = SecurityFinding::query()->where('site_id', $site->id)->orderBy('code')->get();
        $this->assertSame(['file.exposed', 'http.hsts'], $open->pluck('code')->all());
        $this->assertSame(['/.env', ''], $open->pluck('subject')->all());
        $this->assertSame(['critico', 'aviso'], $open->pluck('severity')->all());
        $this->assertSame(['opened', 'opened'], $this->kinds($site));

        $site->refresh();
        $this->assertSame(['critico', 71], [$site->last_status, $site->last_score]);
        $this->assertEqualsWithDelta(now()->addMinutes(360)->getTimestamp(), $site->next_check_at->getTimestamp(), 5);
    }

    public function test_the_same_problem_again_updates_the_finding_without_new_events_or_duplicates(): void
    {
        $site = $this->site();
        $this->record($site, [CheckResult::warning('http.hsts', 'ausente')]);
        $this->travel(6)->hours();

        $this->record($site, [CheckResult::warning('http.hsts', 'ausente de novo')]);

        $this->assertSame(1, SecurityFinding::query()->count());
        $this->assertSame('ausente de novo', SecurityFinding::query()->first()->evidence);
        $this->assertSame(['opened'], $this->kinds($site));
        $this->assertSame(2, SecurityCheck::query()->count());
        $this->assertTrue(SecurityFinding::query()->first()->last_seen_at->gt(SecurityFinding::query()->first()->first_seen_at));
    }

    public function test_a_warning_that_becomes_critical_is_a_worsened_event_and_the_fix_is_resolved_then_reopened(): void
    {
        $site = $this->site();
        $this->record($site, [CheckResult::warning('tls.expiry', 'vence em 12 dia(s)')]);
        $this->record($site, [CheckResult::critical('tls.expiry', 'vence em 5 dia(s)')]);
        $this->record($site, [CheckResult::ok('tls.expiry', 'vence em 89 dias')]);
        $this->record($site, [CheckResult::warning('tls.expiry', 'vence em 12 dia(s)')]);

        $this->assertSame(['opened', 'worsened', 'resolved', 'reopened'], $this->kinds($site));
        $finding = SecurityFinding::query()->sole();
        $this->assertSame(['open', 'aviso'], [$finding->status, $finding->severity]);
        $this->assertNull($finding->resolved_at);
    }

    public function test_a_critical_that_goes_back_to_a_warning_changes_severity_without_an_alarm(): void
    {
        $site = $this->site();
        $this->record($site, [CheckResult::critical('tls.expiry', 'vence em 5 dia(s)')]);
        $this->record($site, [CheckResult::warning('tls.expiry', 'vence em 12 dia(s)')]);

        $this->assertSame(['opened'], $this->kinds($site));
        $this->assertSame('aviso', SecurityFinding::query()->sole()->severity);
    }

    public function test_an_untestable_result_neither_opens_nor_resolves_anything(): void
    {
        $site = $this->site();
        $this->record($site, [CheckResult::warning('http.hsts', 'ausente')]);

        $this->record($site, [CheckResult::untested('http.hsts', 'o site não respondeu')]);

        $this->assertSame('open', SecurityFinding::query()->sole()->status, 'não deu para verificar: o problema continua aberto');
        $this->assertSame(['opened'], $this->kinds($site));
    }

    public function test_a_site_that_did_not_answer_is_an_error_and_touches_no_findings(): void
    {
        $site = $this->site();
        $this->record($site, [CheckResult::warning('http.hsts', 'ausente')]);

        $check = $this->record($site, [CheckResult::untested('http.hsts', 'sem resposta')], reachable: false);

        $this->assertSame('erro', $check->status);
        $this->assertSame('erro', $site->fresh()->last_status);
        $this->assertSame('open', SecurityFinding::query()->sole()->status);
        $this->assertSame(['opened'], $this->kinds($site));
    }

    public function test_a_cookie_that_is_fixed_or_gone_is_resolved_by_its_own_subject(): void
    {
        $site = $this->site();
        $this->record($site, [
            CheckResult::warning('cookie.flags', 'falta Secure', 'sessionid'),
            CheckResult::warning('cookie.flags', 'falta Secure', 'token'),
        ]);

        // O sessionid foi corrigido e o token deixou de ser enviado.
        $this->record($site, [CheckResult::ok('cookie.flags', 'Secure, HttpOnly', 'sessionid')]);

        $this->assertSame(['resolved', 'resolved'], SecurityFinding::query()->orderBy('subject')->pluck('status')->all());
        $this->assertSame(['opened', 'opened', 'resolved', 'resolved'], $this->kinds($site));
    }

    public function test_events_carry_the_subject_and_a_short_detail(): void
    {
        $site = $this->site();
        $this->record($site, [CheckResult::critical('file.exposed', '.env acessível', '/.env')]);

        $this->assertSame('/.env: .env acessível', SecurityEvent::query()->sole()->detail);
    }

    public function test_the_next_scan_respects_the_site_interval_but_never_less_than_the_minimum(): void
    {
        $site = $this->site(['interval_minutes' => 5]);

        $this->record($site, [CheckResult::ok('tls.valid')]);

        $this->assertEqualsWithDelta(now()->addMinutes(60)->getTimestamp(), $site->fresh()->next_check_at->getTimestamp(), 5);
    }

    public function test_old_checks_and_events_leave_after_the_retention(): void
    {
        $site = $this->site();
        config(['secmon.security.retention_days' => 365]);
        $this->record($site, [CheckResult::warning('http.hsts', 'ausente')]);
        $old = $site->checks()->first();
        $old->forceFill(['started_at' => now()->subDays(400)])->save();
        SecurityEvent::query()->update(['created_at' => now()->subDays(400)]);

        $this->record($site, [CheckResult::warning('http.hsts', 'ausente')]);

        $this->assertNull(SecurityCheck::query()->find($old->id));
        $this->assertSame(1, SecurityCheck::query()->count());
        $this->assertSame(0, SecurityEvent::query()->count(), 'evento com mais de 365 dias também sai');
        $this->assertSame(1, SecurityFinding::query()->count(), 'o estado atual do problema fica');
    }

    public function test_a_failed_scan_is_recorded_as_an_error_and_the_next_one_is_scheduled(): void
    {
        $site = $this->site();

        $check = app(ScanRecorder::class)->recordFailure($site, str_repeat('x', 500));

        $this->assertSame('erro', $check->status);
        $this->assertSame(300, strlen($check->error));
        $this->assertTrue($site->fresh()->next_check_at->isFuture());
        $this->assertSame('erro', $site->fresh()->last_status);
    }

    public function test_deleting_the_site_deletes_checks_findings_and_events(): void
    {
        $site = $this->site();
        $this->record($site, [CheckResult::warning('http.hsts', 'ausente')]);

        $site->delete();

        $this->assertSame([0, 0, 0], [SecurityCheck::query()->count(), SecurityFinding::query()->count(), SecurityEvent::query()->count()]);
    }
}
