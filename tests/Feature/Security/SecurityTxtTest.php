<?php

namespace Tests\Feature\Security;

use App\Services\Security\CheckResult;
use App\Services\Security\SiteScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** O /.well-known/security.txt do próprio Security Monitor (RFC 9116). */
class SecurityTxtTest extends TestCase
{
    use RefreshDatabase, SecurityTestHelpers;

    public function test_it_is_plain_utf8_text_with_both_contacts_in_order(): void
    {
        $response = $this->get('/.well-known/security.txt')->assertOk();

        $this->assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame(
            ['Contact: mailto:security@example.com', 'Contact: mailto:abuse@example.com'],
            array_values(preg_grep('/^Contact:/', explode("\n", $response->getContent()))),
        );
        $response->assertSee('Preferred-Languages: pt, en')
            ->assertSee('Canonical: '.route('security-txt'));
    }

    public function test_expires_is_180_days_ahead_at_utc_midnight_so_it_never_goes_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 22:30:00', 'America/Sao_Paulo'));

        // 22:30 em Brasília já é 25/10 em UTC: o Expires conta do dia UTC.
        $this->get('/.well-known/security.txt')->assertSee('Expires: 2027-04-23T00:00:00Z');
    }

    public function test_every_field_line_is_one_the_rfc_knows_and_comments_start_with_a_hash(): void
    {
        $lines = array_filter(explode("\n", $this->get('/.well-known/security.txt')->getContent()));

        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression('/^(#.*|(Contact|Expires|Preferred-Languages|Canonical): \S.*)$/', $line);
        }
    }

    public function test_contacts_come_from_config(): void
    {
        config(['secmon.security_txt.security' => 'seg@exemplo.test', 'secmon.security_txt.abuse' => 'abuso@exemplo.test']);

        $this->get('/.well-known/security.txt')
            ->assertSee('Contact: mailto:seg@exemplo.test')
            ->assertSee('Contact: mailto:abuso@exemplo.test')
            ->assertDontSee('example.com');
    }

    public function test_the_old_address_moves_permanently_to_the_well_known_one(): void
    {
        $this->get('/security.txt')->assertStatus(301)->assertRedirect('/.well-known/security.txt');
    }

    public function test_the_secmon_security_check_rates_our_own_file_as_ok(): void
    {
        $this->setUpSecurity();
        $this->goodDns();
        $this->fakeTls();
        $this->fakeWeb(paths: ['/.well-known/security.txt' => Http::response($this->get('/.well-known/security.txt')->getContent())]);

        $results = collect(app(SiteScanner::class)->scan($this->site())->results)->keyBy(fn (CheckResult $r) => $r->key());

        $this->assertSame('ok', $results['wk.security_txt|']->status);
    }
}
