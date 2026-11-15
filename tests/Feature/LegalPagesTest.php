<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_terms_page_is_public(): void
    {
        $this->get('/termos')
            ->assertOk()
            ->assertSee('<h1 class="text-2xl font-semibold tracking-tight text-white">Termos de Uso</h1>', false)
            ->assertSee('ver canais, enviar mensagens e inserir links')
            ->assertSee('mailto:contato@example.com', false);
    }

    public function test_privacy_page_is_public(): void
    {
        $this->get('/privacidade')
            ->assertOk()
            ->assertSee('<h1 class="text-2xl font-semibold tracking-tight text-white">Política de Privacidade</h1>', false)
            ->assertSee('art. 18')
            ->assertSee('mailto:contato@example.com', false);
    }

    public function test_privacy_page_says_how_long_the_backups_keep_data(): void
    {
        // O ci/servidor/backup-banco.sh guarda 7 diários e 4 semanais no servidor (até 28 dias); no R2 da Cloudflare,
        // a poda diária apaga os diários com mais de 8 dias e os semanais com mais de 29. O texto diz 35, com margem para
        // alguns dias de backup com falha, quando a poda não roda (decisão do dono, 25/10).
        $text = preg_replace('/\s+/u', ' ', strip_tags($this->get('/privacidade')->assertOk()->getContent()));

        $this->assertStringContainsString('Última atualização: 4 de novembro de 2026', $text);
        $this->assertStringContainsString('Todo acesso ao site passa por ela. Também guarda a cópia externa dos backups do banco.', $text);
        $this->assertStringContainsString('Backups do banco: no servidor e numa cópia externa, na Cloudflare, por até 35 dias.', $text);
        $this->assertStringContainsString('Nos backups, esses dados somem em até 35 dias depois disso.', $text);
    }

    public function test_privacy_page_no_longer_tracks_ad_visits(): void
    {
        // Admin → Campanhas saiu (04/11): a divulgação se mede só pelos registros de acesso (Admin → Acessos → Origens).
        $text = preg_replace('/\s+/u', ' ', strip_tags($this->get('/privacidade')->assertOk()->getContent()));

        $this->assertStringNotContainsString('Quem chega por um anúncio ou link de divulgação do Security Monitor', $text);
        $this->assertStringNotContainsString('Visitas que chegaram por anúncio', $text);
        $this->assertStringNotContainsString('liga as páginas dessa visita', $text);
        $this->assertStringContainsString('Medir a nossa divulgação: pelos registros de acesso, saber de quais sites vêm as visitas', $text);
        $this->assertStringContainsString('endereço acessado, endereço de onde veio, tempo na página', $text);
    }

    public function test_contact_comes_from_config(): void
    {
        config(['secmon.contact_email' => 'privacidade@exemplo.test']);

        $this->get('/privacidade')
            ->assertSee('mailto:privacidade@exemplo.test', false)
            ->assertDontSee('contato@example.com');
    }

    public function test_footer_and_signup_link_both_documents(): void
    {
        $this->get(route('about'))
            ->assertSee('href="'.route('terms').'"', false)
            ->assertSee('href="'.route('privacy').'"', false);

        $this->get(route('register'))
            ->assertSee('Ao criar a conta você concorda com os');
    }
}
