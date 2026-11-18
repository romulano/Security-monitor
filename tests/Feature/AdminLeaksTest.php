<?php

namespace Tests\Feature;

use App\Models\FreeMailDomain;
use App\Models\LeakedCredential;
use App\Models\LeakImportLine;
use App\Models\User;
use App\Services\Leaks\FreeMailList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admin → Vazamentos: a fila do humano, as credenciais guardadas e a lista de e-mail genérico. */
class AdminLeaksTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function line(string $raw = 'Usuaria999:CIDADE2026:https://www.exemplo88.net/', string $reason = 'ordem_ambigua'): LeakImportLine
    {
        return LeakImportLine::create([
            'source' => 'Amostra2026',
            'line_no' => 6,
            'raw' => $raw,
            'reason' => $reason,
            'country' => 'IN',
            'fields' => explode(':', $raw, 2) === [] ? [] : ['Usuaria999', 'CIDADE2026', 'https://www.exemplo88.net/'],
            'fingerprint' => md5($raw),
        ]);
    }

    // ------------------------------------------------------------------ sem login

    public function test_the_owner_gets_in_without_logging_in(): void
    {
        // Aqui não existe login: o dono da instalação é autenticado em toda requisição e é admin.
        $line = $this->line();

        $this->get(route('admin.leaks'))->assertOk()->assertSee('Vazamentos');
        $this->get(route('admin.leaks', ['ver' => 'credenciais']))->assertOk();
        $this->delete(route('admin.leaks.discard', $line))->assertRedirect();

        $this->assertDatabaseMissing('leak_import_lines', ['id' => $line->id]);
    }

    public function test_the_admin_menu_has_the_entry(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Vazamentos');
    }

    // ------------------------------------------------------------------ a fila

    public function test_the_queue_shows_the_line_with_its_reason_and_a_form(): void
    {
        $line = $this->line();

        $this->actingAs($this->admin())->get(route('admin.leaks'))
            ->assertOk()
            ->assertSee('Usuaria999:CIDADE2026:https://www.exemplo88.net/')
            ->assertSee('login e senha são intercambiáveis')
            ->assertSee('Amostra2026')
            ->assertSee(route('admin.leaks.classify', $line), false)
            ->assertSee(route('admin.leaks.discard', $line), false);
    }

    public function test_the_admin_turns_a_line_into_a_credential(): void
    {
        $line = $this->line();

        $this->actingAs($this->admin())->post(route('admin.leaks.classify', $line), [
            'login' => 'Usuaria999@exemplo88.net',
            'password' => 'CIDADE2026',
            'url' => 'https://www.exemplo88.net/',
        ])->assertRedirect();

        $credential = LeakedCredential::sole();
        $this->assertSame('usuaria999@exemplo88.net', $credential->email);
        $this->assertSame('exemplo88.net', $credential->domain);
        $this->assertSame('CIDADE2026', $credential->password);
        $this->assertSame('www.exemplo88.net', $credential->site_host);
        $this->assertSame('Amostra2026', $credential->source);
        $this->assertSame('IN', $credential->country);

        $line->refresh();
        $this->assertSame('resolved', $line->status);
        $this->assertSame($credential->id, $line->leaked_credential_id);
        $this->assertNotNull($line->resolved_at);
    }

    public function test_a_generic_email_is_refused_because_only_companies_are_kept(): void
    {
        $line = $this->line();

        $this->actingAs($this->admin())->post(route('admin.leaks.classify', $line), [
            'login' => 'alguem@gmail.com', 'password' => 'CIDADE2026',
        ])->assertRedirect();

        $this->assertSame(0, LeakedCredential::count());
        $this->assertSame('pending', $line->fresh()->status, 'a linha fica na fila para o admin decidir');
    }

    public function test_a_login_that_is_not_an_email_is_refused(): void
    {
        $line = $this->line();

        $this->actingAs($this->admin())->post(route('admin.leaks.classify', $line), [
            'login' => 'Usuaria999', 'password' => 'CIDADE2026',
        ])->assertRedirect();

        $this->assertSame(0, LeakedCredential::count());
        $this->assertSame('pending', $line->fresh()->status);
    }

    /** Descartar APAGA: o dono não quer guardar lixo que ele já disse que é lixo. */
    public function test_discarding_deletes_the_line_instead_of_keeping_it(): void
    {
        $line = $this->line();

        $this->actingAs($this->admin())->delete(route('admin.leaks.discard', $line))->assertRedirect();

        $this->assertDatabaseMissing('leak_import_lines', ['id' => $line->id]);
        $this->assertSame(0, LeakImportLine::count());
        $this->assertSame(0, LeakedCredential::count());
    }

    public function test_a_line_that_was_discarded_cannot_be_classified_afterwards(): void
    {
        $line = $this->line();
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('admin.leaks.discard', $line));

        // A linha não existe mais: o vínculo da rota devolve 404, e nada é gravado.
        $this->actingAs($admin)->post(route('admin.leaks.classify', $line), [
            'login' => 'ana@exemplo88.net', 'password' => 'x',
        ])->assertNotFound();

        $this->assertSame(0, LeakedCredential::count());
    }

    public function test_there_is_no_discarded_tab_anymore(): void
    {
        $this->actingAs($this->admin())->get(route('admin.leaks'))
            ->assertOk()
            ->assertDontSee('Descartadas');
    }

    // ------------------------------------------------------------------ as credenciais guardadas

    private function credential(string $email, string $source = 'Amostra2026'): LeakedCredential
    {
        return LeakedCredential::create([
            'domain' => substr($email, strrpos($email, '@') + 1),
            'email' => $email,
            'password' => 'Senha!'.strlen($email),
            'url' => 'https://portal.acme.com.br/entrar',
            'site_host' => 'portal.acme.com.br',
            'source' => $source,
            'fingerprint' => md5($email),
        ]);
    }

    public function test_the_credentials_tab_lists_the_newest_first(): void
    {
        $velha = $this->credential('velha@acme.com.br');
        $nova = $this->credential('nova@acme.com.br');

        $this->actingAs($this->admin())->get(route('admin.leaks', ['ver' => 'credenciais']))
            ->assertOk()
            ->assertSeeInOrder([$nova->email, $velha->email])
            ->assertSee('portal.acme.com.br')
            ->assertSee('100 por página');
    }

    public function test_the_search_finds_by_domain_email_site_and_dump(): void
    {
        $this->credential('ana@acme.com.br');
        $this->credential('ze@outra.com.br', 'Collection #1');
        $admin = $this->admin();

        foreach (['acme.com.br' => 'ana@acme.com.br', 'ana@' => 'ana@acme.com.br', 'Collection #1' => 'ze@outra.com.br'] as $termo => $esperado) {
            $this->actingAs($admin)->get(route('admin.leaks', ['ver' => 'credenciais', 'q' => $termo]))
                ->assertOk()
                ->assertSee($esperado);
        }

        $this->actingAs($admin)->get(route('admin.leaks', ['ver' => 'credenciais', 'q' => 'naoexiste']))
            ->assertOk()
            ->assertSee('Nada começa com');
    }

    /** `@dominio` é como se escreve "todo mundo desse domínio" — e antes não achava nada. */
    public function test_an_at_sign_searches_the_whole_domain(): void
    {
        $this->credential('ana@passarinho-exemplo.com');
        $this->credential('bia@passarinho-exemplo.com');
        $this->credential('ze@outra.com.br');
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.leaks', ['ver' => 'credenciais', 'q' => '@passarinho-exemplo.com']))
            ->assertOk()
            ->assertSee('2 credencial(is)')
            ->assertSee('ana@passarinho-exemplo.com')
            ->assertSee('bia@passarinho-exemplo.com')
            ->assertDontSee('ze@outra.com.br');
    }

    public function test_the_at_sign_in_the_middle_still_searches_the_email(): void
    {
        $this->credential('ana@passarinho-exemplo.com');
        $this->credential('bia@passarinho-exemplo.com');

        $this->actingAs($this->admin())->get(route('admin.leaks', ['ver' => 'credenciais', 'q' => 'ana@passarinho-exemplo.com']))
            ->assertOk()
            ->assertSee('1 credencial(is)')
            ->assertSee('ana@passarinho-exemplo.com')
            ->assertDontSee('bia@passarinho-exemplo.com');
    }

    public function test_the_list_breaks_into_pages_of_a_hundred(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->credential("p{$i}@acme.com.br");
        }

        // A mais nova é a última criada; a mais velha sobra para a segunda página.
        $this->actingAs($this->admin())->get(route('admin.leaks', ['ver' => 'credenciais']))
            ->assertOk()
            ->assertSee('p100@acme.com.br')
            ->assertDontSee('p0@acme.com.br');

        $this->actingAs($this->admin())->get(route('admin.leaks', ['ver' => 'credenciais', 'page' => 2]))
            ->assertOk()
            ->assertSee('p0@acme.com.br');
    }

    // ------------------------------------------------------------------ a lista de genéricos

    public function test_the_admin_registers_a_domain_and_the_list_starts_refusing_it(): void
    {
        $list = app(FreeMailList::class);
        $this->assertFalse($list->has('webmailnovo.com.br'));

        $this->actingAs($this->admin())->post(route('admin.leaks.free-mail.store'), [
            'kind' => 'domain', 'value' => '  WebmailNovo.com.BR  ', 'note' => 'apareceu em 2026',
        ])->assertRedirect();

        $this->assertDatabaseHas('free_mail_domains', ['kind' => 'domain', 'value' => 'webmailnovo.com.br']);
        // A lista é cacheada: cadastrar tem de invalidar, senão a carga seguiria com a lista velha.
        $this->assertTrue(app(FreeMailList::class)->has('webmailnovo.com.br'));
    }

    public function test_the_admin_removes_a_domain_and_the_list_stops_refusing_it(): void
    {
        $gmail = FreeMailDomain::where('value', 'gmail.com')->firstOrFail();
        $this->assertTrue(app(FreeMailList::class)->has('gmail.com'));

        $this->actingAs($this->admin())->delete(route('admin.leaks.free-mail.destroy', $gmail))->assertRedirect();

        $this->assertDatabaseMissing('free_mail_domains', ['value' => 'gmail.com']);
        $this->assertFalse(app(FreeMailList::class)->has('gmail.com'));
    }

    public function test_the_list_refuses_junk_and_duplicates(): void
    {
        $admin = $this->admin();

        // Marca é um rótulo só, sem ponto.
        $this->actingAs($admin)->post(route('admin.leaks.free-mail.store'), ['kind' => 'brand', 'value' => 'hotmail.com'])
            ->assertSessionHasErrors('value');

        // Domínio precisa ser completo.
        $this->actingAs($admin)->post(route('admin.leaks.free-mail.store'), ['kind' => 'domain', 'value' => 'gmail'])
            ->assertSessionHasErrors('value');

        // Já está na lista (vem da semente da migration).
        $this->actingAs($admin)->post(route('admin.leaks.free-mail.store'), ['kind' => 'domain', 'value' => 'gmail.com'])
            ->assertSessionHasErrors('value');
    }

    public function test_the_generic_tab_lists_the_brands_and_the_domains(): void
    {
        $this->actingAs($this->admin())->get(route('admin.leaks', ['ver' => 'genericos']))
            ->assertOk()
            ->assertSee('gmail.com')
            ->assertSee('hotmail')
            ->assertSee(route('admin.leaks.free-mail.store'), false);
    }
}
