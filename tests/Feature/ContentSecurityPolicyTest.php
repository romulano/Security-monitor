<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Contra XSS: só roda o JavaScript do site, do captcha e o inline com o nonce da requisição. */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function nonce(string $policy): string
    {
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)' https:\/\/challenges\.cloudflare\.com(;|$)/", $policy);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $m);

        return $m[1];
    }

    public function test_pages_and_error_pages_only_run_the_sites_own_scripts(): void
    {
        foreach (['/', '/sobre', '/login', '/nao-existe-'.uniqid()] as $path) {
            $policy = (string) $this->get($path)->headers->get('Content-Security-Policy');

            $this->nonce($policy);
            $this->assertStringNotContainsString('unsafe-eval', $policy, $path);
            $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy, $path);
            foreach (["object-src 'none'", "base-uri 'self'", "frame-ancestors 'self'", "form-action 'self' https://github.com", 'frame-src https://challenges.cloudflare.com'] as $directive) {
                $this->assertStringContainsString($directive, $policy, $path);
            }
        }
    }

    public function test_each_request_has_its_own_nonce_and_the_build_scripts_carry_it(): void
    {
        $first = $this->get('/sobre');
        $second = $this->get('/sobre');

        $nonce = $this->nonce((string) $first->headers->get('Content-Security-Policy'));
        $this->assertNotSame($nonce, $this->nonce((string) $second->headers->get('Content-Security-Policy')));
        $first->assertSee('nonce="'.$nonce.'"', false);
    }

    public function test_the_inline_reload_of_the_admin_carries_the_nonce(): void
    {
        $article = Article::create([
            'url' => 'https://www.darkreading.com/beyondtrust',
            'url_hash' => hash('sha256', 'https://www.darkreading.com/beyondtrust'),
            'source_host' => 'darkreading.com',
            'original_title' => 'BeyondTrust zero-day exploited',
            'content' => 'Now BeyondTrust joins the group.',
            'content_chars' => 32,
            'extraction' => 'summary',
            'collected_at' => now(),
            'status' => 'pending',
        ]);
        $article->forceFill(['manual_media' => ['state' => 'processing', 'prints' => [], 'images' => [], 'figures' => []]])->save();
        $response = $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.articles.text', $article));

        $nonce = $this->nonce((string) $response->headers->get('Content-Security-Policy'));
        $response->assertSee('<script nonce="'.$nonce.'">setTimeout', false);
    }
}
