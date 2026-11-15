<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->withHeaders(self::FORGED)->get(route('about'))
            ->assertOk()
            ->assertDontSee('atacante.invalid')
            ->assertSee('href="'.route('feed.index').'"', false);
    }

    public function test_the_security_txt_canonical_keeps_the_site_host(): void
    {
        $this->withHeaders(self::FORGED)->get('/.well-known/security.txt')
            ->assertOk()
            ->assertDontSee('atacante.invalid')
            ->assertSee('Canonical: '.route('security-txt'));
    }
}
