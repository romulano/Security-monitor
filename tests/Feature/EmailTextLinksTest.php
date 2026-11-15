<?php

namespace Tests\Feature;

use App\Mail\VerifyEmail;
use App\Mail\WebhookPaused;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A versão em texto puro dos e-mails: o link sai como foi gerado. Com `{{ }}`, o Blade escapava o `&` do link
 * assinado para `&amp;`, e quem abria o link pela versão em texto chegava sem o `signature` (403 ou "venceu").
 */
class EmailTextLinksTest extends TestCase
{
    /** A versão em texto do e-mail, como o Mailer monta. */
    private function text(Mailable $mail): string
    {
        return view($mail->content()->text, $mail->buildViewData())->render();
    }

    /** O link da linha que começa com o endereço do app (ou que o contém, depois de um rótulo). */
    private function linkIn(string $text): string
    {
        preg_match('#https?://\S+#', $text, $match);

        return $match[0] ?? $this->fail("Sem link no e-mail:\n{$text}");
    }

    public function test_signed_links_in_the_text_version_keep_the_signature_and_validate(): void
    {
        $mails = [
            'confirmação do cadastro' => fn () => new VerifyEmail('Ana', URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['user' => 1, 'hash' => sha1('ana@acme.com')]), 60),
        ];

        foreach ($mails as $what => $mail) {
            $text = $this->text($mail());
            $this->assertStringNotContainsString('&amp;', $text, $what);

            $link = $this->linkIn($text);
            $this->assertStringContainsString('&signature=', $link, $what);
            $this->assertTrue(URL::hasValidSignature(Request::create($link)), "O link de {$what} não valida: {$link}");
        }
    }

    public function test_a_link_with_a_query_string_reaches_the_text_version_as_is(): void
    {
        $url = 'https://secmon.test/alertas/slack?canal=7&setor=geral';
        $text = $this->text(new WebhookPaused('Ana', 'Webhook Prod', 'hooks.acme.com.br', 5, 'HTTP 500', $url));

        $this->assertStringContainsString($url, $text);
        $this->assertStringNotContainsString('&amp;', $text);
    }

    /** A varredura: nenhuma view de texto de e-mail escapa uma variável de link (`$url`, `$link`, `$entryUrl`…). */
    public function test_no_text_email_view_escapes_a_link_variable(): void
    {
        $views = glob(resource_path('views/emails/*-text.blade.php'));
        $this->assertNotEmpty($views);

        foreach ($views as $view) {
            $escaped = preg_match_all('/\{\{\s*\$\w*(?:url|link)\w*\b[^}]*\}\}/i', (string) file_get_contents($view), $found);
            $this->assertSame(0, $escaped, basename($view).' escapa o link com {{ }}: use {!! !!} na versão em texto. Achado: '.implode(', ', $found[0]));
        }
    }
}
