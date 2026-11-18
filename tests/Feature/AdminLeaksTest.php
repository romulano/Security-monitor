<?php

namespace Tests\Feature;

use App\Models\FreeMailDomain;
use App\Models\LeakedCredential;
use App\Models\LeakImportLine;
use App\Models\User;
use App\Services\Leaks\FreeMailList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admin → Vazamentos: a fila do humano e a lista de e-mail genérico. */
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
        $this->delete(route('admin.leaks.discard', $line))->assertRedirect();

        $this->assertSame('discarded', $line->fresh()->status);
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

    public function test_the_admin_discards_a_line(): void
    {
        $line = $this->line();
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('admin.leaks.discard', $line))->assertRedirect();

        $line->refresh();
        $this->assertSame('discarded', $line->status);
        $this->assertSame($admin->id, $line->resolved_by);
        $this->assertSame(0, LeakedCredential::count());
    }

    public function test_a_line_is_decided_only_once(): void
    {
        $line = $this->line();
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('admin.leaks.discard', $line));
        $this->actingAs($admin)->post(route('admin.leaks.classify', $line), [
            'login' => 'ana@exemplo88.net', 'password' => 'x',
        ])->assertRedirect();

        $this->assertSame(0, LeakedCredential::count());
        $this->assertSame('discarded', $line->fresh()->status);
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
