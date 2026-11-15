<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Jobs\SendEmail;
use App\Mail\CompanyInviteLink;
use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\CompanyInvites;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** E1c: o convite da empresa (R12, D4): convidar, reenviar e cancelar. O aceite é da E1c1. */
class CompanyInvitesTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        config(['secmon.enterprise.enabled' => true]);
        Queue::fake();
        $this->acme = app(Companies::class)->create('Acme', 'Admin Acme', 'ana@acme.com');
        app(Companies::class)->enable($this->acme);
        $this->ana = User::query()->where('email', 'ana@acme.com')->sole();
    }

    private function invites(): CompanyInvites
    {
        return app(CompanyInvites::class);
    }

    private function invite(string $email = 'bia@acme.com'): string
    {
        $this->invites()->invite($this->acme, $this->ana, $email, CompanyRole::Member);

        return $this->lastLink();
    }

    private function lastLink(): string
    {
        return Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->last()->mail->url;
    }

    private function assertRefused(callable $action, string $contains): void
    {
        try {
            $action();
            $this->fail("Não recusou: {$contains}");
        } catch (ActionRefused $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    public function test_the_invite_goes_by_email_and_only_the_hash_stays(): void
    {
        $link = $this->invite('Bia@ACME.com');
        $token = basename($link);
        $invite = CompanyInvite::query()->sole();

        $this->assertStringEndsWith('/empresa/convite/'.$token, $link);
        $this->assertSame(48, strlen($token));
        $this->assertSame('bia@acme.com', $invite->email);
        $this->assertSame(hash('sha256', $token), $invite->token_hash);
        $this->assertFalse(DB::table('company_invites')->where('token_hash', $token)->exists());
        $this->assertTrue($invite->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)));
        $this->assertSame($this->ana->id, $invite->invited_by);
        $this->assertArrayNotHasKey('token_hash', $invite->toArray());
        $this->assertSame(2, $this->invites()->seatsTaken($this->acme));
        $this->assertSame('bia@acme.com', DB::table('email_messages')->where('kind', 'empresa-convite')->value('to_address'));
    }

    public function test_resending_renews_and_cancelling_frees_the_seat(): void
    {
        $old = $this->invite();
        $invite = CompanyInvite::query()->sole();
        $hash = $invite->token_hash;

        // O vencido não ocupa vaga; reenviar renova o token e o prazo.
        $this->travel(8)->days();
        $this->assertSame(1, $this->invites()->seatsTaken($this->acme));
        $this->invites()->resend($invite);
        $this->assertNotSame($old, $this->lastLink());
        $this->assertNotSame($hash, $invite->fresh()->token_hash);
        $this->assertTrue($invite->fresh()->isPending());
        $this->assertSame(2, $this->invites()->seatsTaken($this->acme));

        $this->invites()->cancel($invite->fresh());
        $this->assertNotNull($invite->fresh()->revoked_at);
        $this->assertSame(1, $this->invites()->seatsTaken($this->acme));
        $this->assertRefused(fn () => $this->invites()->resend($invite->fresh()), 'aceito ou cancelado');
    }

    public function test_the_eleventh_account_is_refused_counting_pending_invites(): void
    {
        foreach (range(1, 8) as $i) {
            $this->acme->members()->forceCreate(['user_id' => User::factory()->create()->id, 'role' => CompanyRole::Member, 'joined_at' => now()]);
        }

        // 9 contas + 1 convite = 10: o 11º não cabe; cancelar libera a vaga.
        $this->invite('decima@acme.com');
        $this->assertRefused(fn () => $this->invite('decima-primeira@acme.com'), '10 de 10');

        $this->invites()->cancel(CompanyInvite::query()->sole());
        $this->invite('decima-primeira@acme.com');
        $this->assertSame(10, $this->invites()->seatsTaken($this->acme));
    }

    public function test_a_personal_account_is_never_invited(): void
    {
        User::factory()->create(['email' => 'Pessoa@Gmail.com']);

        $this->assertRefused(fn () => $this->invite('pessoa@gmail.com'), 'convide outro e-mail');
        $this->assertSame(0, CompanyInvite::query()->count());
    }

    public function test_no_admin_invite_no_duplicate_and_no_invalid_email(): void
    {
        $this->assertRefused(fn () => $this->invites()->invite($this->acme, $this->ana, 'chefe@acme.com', CompanyRole::Admin), 'uma admin só');

        $this->invite();
        $this->assertRefused(fn () => $this->invite('BIA@acme.com'), 'Reenviar');

        // G3: o serviço confere o formato também.
        foreach (['não é e-mail', str_repeat('a', 250).'@acme.com'] as $bad) {
            $this->assertRefused(fn () => $this->invite($bad), 'não é válido');
        }
        $this->assertSame(1, CompanyInvite::query()->count());
    }

    public function test_the_daily_cap_counts_invites_and_resends(): void
    {
        app(Companies::class)->setLimits($this->acme, ['daily_invites' => 2]);
        $this->invite('bia@acme.com');
        $this->invites()->resend(CompanyInvite::query()->sole());

        $this->assertRefused(fn () => $this->invite('caio@acme.com'), '2 convites nas últimas 24 horas');

        $this->travel(25)->hours();
        $this->invite('caio@acme.com');
        $this->assertSame(2, CompanyInvite::query()->count());
    }

    // F3 do Red Team: a comparação de e-mail é sempre a do lower() do banco, também fora do ASCII.
    public function test_the_email_is_compared_by_the_database_lower_also_outside_ascii(): void
    {
        User::factory()->create(['email' => 'İnes@acme.com']);

        foreach (['İNES@acme.com', 'İnes@ACME.com'] as $email) {
            $this->assertRefused(fn () => $this->invite($email), 'convide outro e-mail');
        }

        $this->invite('Érica@Acme.com');
        $this->assertSame(DB::scalar('select lower(?)', ['Érica@Acme.com']), CompanyInvite::query()->sole()->email);
        $this->assertRefused(fn () => $this->invite('ÉRICA@acme.com'), 'Reenviar');
    }

    public function test_the_migration_goes_down_and_up(): void
    {
        $this->invite();
        $migration = require database_path('migrations/2026_12_09_000100_create_company_invites_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('company_invites'));
        $migration->up();
        $this->assertTrue(Schema::hasColumns('company_invites', ['company_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'accepted_user_id', 'revoked_at']));
    }
}
