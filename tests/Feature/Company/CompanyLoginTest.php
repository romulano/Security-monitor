<?php

namespace Tests\Feature\Company;

use App\Models\User;
use App\Services\Company\Companies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A conta de empresa só entra com a empresa ativa e a conta não desativada (Red Team da E1a). */
class CompanyLoginTest extends TestCase
{
    use RefreshDatabase;

    private function companies(): Companies
    {
        return app(Companies::class);
    }

    private function admin(string $email = 'ana@acme.com'): User
    {
        return User::query()->where('email', $email)->sole();
    }

    // A7 — empresa existe com a chave desligada (E1b cria antes da E7c ligar): a conta entra e usa o site como pessoal.
    public function test_rt_a7_conta_de_empresa_inativa_nao_entra(): void
    {
        $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
        $this->admin()->forceFill(['password' => 'senha-da-ana-123', 'email_verified_at' => now()])->save();

        $this->post(route('login'), ['email' => 'ana@acme.com', 'password' => 'senha-da-ana-123']);

        $this->assertGuest();
    }

    // A7b — com a empresa ativa a admin entra; desativada (§4.6) não entra, nem pelo GitHub.
    public function test_rt_a7b_empresa_ativa_entra_e_conta_desativada_nao_entra_nem_pelo_github(): void
    {
        config(['secmon.enterprise.enabled' => true, 'services.github.client_id' => 'Iv1.abc', 'services.github.client_secret' => 'segredo']);
        $acme = $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
        $acme->forceFill(['enterprise_enabled_at' => now()])->save();
        $ana = $this->admin();
        $ana->forceFill(['password' => 'senha-da-ana-123', 'email_verified_at' => now()])->save();

        $this->post(route('login'), ['email' => 'ana@acme.com', 'password' => 'senha-da-ana-123']);
        $this->assertAuthenticatedAs($ana);
        auth()->logout();

        $ana->companyMembership->forceFill(['deactivated_at' => now()])->save();
        $this->post(route('login'), ['email' => 'ana@acme.com', 'password' => 'senha-da-ana-123']);
        $this->assertGuest();

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_token']),
            'api.github.com/user/emails' => Http::response([['email' => 'ana@acme.com', 'primary' => true, 'verified' => true]]),
            'api.github.com/user' => Http::response(['id' => 77, 'login' => 'ana', 'name' => 'Ana']),
            'api.github.com/applications/*' => Http::response('', 204),
        ]);
        parse_str(parse_url($this->get(route('login.github'))->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->get(route('login.github.callback', ['code' => 'c', 'state' => $q['state']]));
        $this->assertGuest();
        $this->assertNull($ana->fresh()->github_id);
    }
}
