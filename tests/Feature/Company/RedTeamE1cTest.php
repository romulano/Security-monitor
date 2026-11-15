<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Jobs\SendEmail;
use App\Mail\CompanyInviteLink;
use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\PageView;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\CompanyInvites;
use App\Services\Monitoring\RecordedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

/** Red Team da E1c (convite e aceite). Os "rt" falham na ponta da branch; os "evidência" passam. */
class RedTeamE1cTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        config(['secmon.enterprise.enabled' => true]);
        Queue::fake();
        $this->acme = app(Companies::class)->create('Acme', 'Ana', 'ana@acme.com');
        app(Companies::class)->enable($this->acme);
        $this->ana = User::query()->where('email', 'ana@acme.com')->sole();
    }

    private function invites(): CompanyInvites
    {
        return app(CompanyInvites::class);
    }

    private function invite(string $email = 'bia@acme.com', ?Company $company = null): string
    {
        $this->invites()->invite($company ?? $this->acme, $this->ana, $email, CompanyRole::Member);

        return Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->last()->mail->url;
    }

    private function accept(string $link)
    {
        return $this->post($link, ['name' => 'Bia', 'password' => 'senha-da-bia-1', 'password_confirmation' => 'senha-da-bia-1']);
    }

    // G1 — o İ: o convite guarda o mb_strtolower ("i̇pek") e o banco compara com lower() ("ipek"): a conta pessoal não é vista.
    public function test_rt_g1_convite_para_email_com_i_turco_de_conta_pessoal_e_recusado(): void
    {
        User::factory()->create(['email' => 'İPEK@acme.com']);

        try {
            $this->invite('İPEK@acme.com');
        } catch (ActionRefused) {
            // recusar é o desejado
        }

        $this->assertSame(0, CompanyInvite::query()->count(), 'Convidou o e-mail de uma conta pessoal (R12).');
    }

    // G2 — o token do convite fica gravado no caminho em Admin → Acessos (vale como senha por 7 dias).
    public function test_rt_g2_o_token_do_convite_nao_fica_em_admin_acessos(): void
    {
        $link = $this->invite();
        $token = basename(parse_url($link, PHP_URL_PATH));

        $this->get($link)->assertOk();
        $this->accept($link)->assertRedirect(route('account.show'));
        $this->withHeader('Referer', $link)->get(route('account.show'));

        $recorded = PageView::query()->get(['path', 'referer'])->toJson();
        $this->assertStringNotContainsString($token, $recorded, 'O token do convite ficou em page_views.');
        $this->assertSame(RecordedUrl::SECRET_PATHS['/empresa/convite/'], '/'.app('router')->getRoutes()->getByName('company.invite')->uri());
    }

    // G3 — o serviço aceita qualquer texto como e-mail, e o aceite cria a conta com ele.
    public function test_rt_g3_o_convite_nao_aceita_um_email_invalido(): void
    {
        foreach (['não é e-mail', 'a@', '<b>@x.com'] as $bad) {
            try {
                $this->invites()->invite($this->acme, $this->ana, $bad, CompanyRole::Member);
            } catch (Throwable) {
                // recusar é o desejado
            }
        }

        $this->assertSame(0, CompanyInvite::query()->count(), 'Convite com e-mail inválido.');
    }

    // Evidência — limite de 10 com os pendentes, aceite com a empresa cheia, papel admin, R12 depois do convite, corrida com o cadastro.
    public function test_evidencia_limite_papel_e_r12(): void
    {
        foreach (range(1, 9) as $i) {
            $this->invite("p{$i}@acme.com");
        }
        $this->assertSame(10, $this->invites()->seatsTaken($this->acme));
        $this->expectRefusal(fn () => $this->invite('p10@acme.com'));
        $this->expectRefusal(fn () => $this->invites()->invite($this->acme, $this->ana, 'chefe@acme.com', CompanyRole::Admin));

        // A conta pessoal criada depois do convite: o aceite recusa e nada liga.
        $link = Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->first()->mail->url;
        User::factory()->create(['email' => 'P1@ACME.com']);
        $this->accept($link)->assertRedirect(route('login'));
        $this->assertFalse(User::query()->where('email', 'P1@ACME.com')->sole()->isCompanyAccount());

        // A corrida: outro cadastro do mesmo e-mail grava no meio do aceite.
        $link = Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->get(1)->mail->url;
        User::creating(function (User $user) {
            if ($user->email === 'p2@acme.com') {
                User::flushEventListeners();
                DB::table('users')->insert(['name' => 'Outro', 'email' => 'P2@acme.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->accept($link)->assertRedirect(route('login'));
        // 0 também serve: o insert do "outro cadastro" volta junto com a transação do aceite.
        $this->assertLessThanOrEqual(1, User::query()->whereRaw('lower(email) = ?', ['p2@acme.com'])->count());
        $this->assertSame(1, $this->acme->members()->count());

        // O limite baixado abaixo das contas: o aceite recusa mesmo com o convite pendente.
        $this->acme->forceFill(['limit_accounts' => 1])->save();
        $link = Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->get(2)->mail->url;
        $this->accept($link)->assertRedirect();
        $this->assertSame(1, $this->acme->members()->count());
    }

    // Evidência — empresa suspensa ou desligada, convite cancelado ou reenviado, visitante logado, token só como hash.
    public function test_evidencia_estados_do_convite(): void
    {
        $first = $this->invite();
        $invite = CompanyInvite::query()->sole();
        $this->assertStringNotContainsString(basename($first), json_encode(DB::table('company_invites')->first()));

        $this->acme->forceFill(['suspended_at' => now()])->save();
        $this->get($first)->assertNotFound();
        $this->acme->forceFill(['suspended_at' => null, 'enterprise_enabled_at' => null])->save();
        $this->accept($first)->assertNotFound();
        $this->acme->forceFill(['enterprise_enabled_at' => now()])->save();

        $this->actingAs(User::factory()->create())->get($first)->assertRedirect();
        auth()->logout();

        $this->invites()->resend($invite);
        $this->get($first)->assertNotFound();
        $second = Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->last()->mail->url;
        $this->invites()->cancel($invite->fresh());
        $this->accept($second)->assertNotFound();
        $this->assertSame(0, User::query()->where('email', 'bia@acme.com')->count());

        // O convite de outra empresa entra na outra empresa, nunca na Acme.
        $outra = app(Companies::class)->create('Outra', 'Beto', 'beto@outra.com');
        app(Companies::class)->enable($outra);
        $this->invites()->invite($outra, User::query()->where('email', 'beto@outra.com')->sole(), 'caio@outra.com', CompanyRole::SectorManager);
        $this->accept(Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->last()->mail->url);
        $caio = User::query()->where('email', 'caio@outra.com')->sole();
        $this->assertTrue($caio->company->is($outra));
        $this->assertSame(CompanyRole::SectorManager, $caio->companyRole());
    }

    private function expectRefusal(callable $action): void
    {
        try {
            $action();
            $this->fail('Não recusou.');
        } catch (ActionRefused) {
            $this->addToAssertionCount(1);
        }
    }
}
