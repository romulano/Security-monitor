<?php

namespace Tests\Feature\Security;

use App\Jobs\SendEmail;
use App\Mail\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Os cabeçalhos X-Forwarded-* em que o app confia (bootstrap/app.php). O X-Forwarded-Prefix
 * chega do visitante (a borda não o limpa) e não pode mudar as URLs que o app gera.
 */
class TrustedProxyHeadersTest extends TestCase
{
    use RefreshDatabase;

    private const FORGED = ['X-Forwarded-Prefix' => '.atacante.invalid'];

    public function test_a_forged_prefix_does_not_change_the_urls_of_the_page(): void
    {
        $this->withHeaders(self::FORGED)->get(route('login'))
            ->assertOk()
            ->assertDontSee('atacante.invalid')
            ->assertSee('action="'.route('login').'"', false);
    }

    public function test_a_forged_prefix_does_not_change_the_redirect(): void
    {
        // O endereço calculado antes: o assertRedirect usaria o gerador de URLs da própria requisição.
        $login = route('login');

        $this->withHeaders(['X-Forwarded-Prefix' => '@atacante.invalid'])->get('/alertas')
            ->assertHeader('Location', $login);
    }

    public function test_the_confirmation_link_in_the_email_keeps_the_site_host(): void
    {
        Queue::fake();

        $this->withHeaders(self::FORGED)->post(route('register'), [
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'password' => 'minha-senha-123',
            'password_confirmation' => 'minha-senha-123',
        ])->assertRedirect(route('verification.notice'));

        $job = Queue::pushed(SendEmail::class)->last();
        $this->assertInstanceOf(VerifyEmail::class, $job->mail);
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/conta/confirmar-email/', $job->mail->url);
    }

    public function test_the_security_txt_canonical_keeps_the_site_host(): void
    {
        $this->withHeaders(self::FORGED)->get('/.well-known/security.txt')
            ->assertOk()
            ->assertDontSee('atacante.invalid')
            ->assertSee('Canonical: '.route('security-txt'));
    }

    public function test_the_https_and_the_visitor_ip_from_the_edge_still_count(): void
    {
        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])->get(route('login'))
            ->assertOk()
            ->assertSee('action="'.str_replace('http://', 'https://', route('login')).'"', false);

        $this->assertSame('203.0.113.7', request()->ip());
    }
}
