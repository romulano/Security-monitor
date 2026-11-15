import { test, expect } from '@playwright/test';

// O que o nginx de produção (docker/nginx/prod.conf) e o Laravel prometem.

test('cabeçalhos de segurança em toda resposta', async ({ request }) => {
    for (const caminho of ['/', '/login', '/api/news', '/nao-existe']) {
        const res = await request.get(caminho, { maxRedirects: 0 });
        const h = res.headers();

        expect(h['x-content-type-options'], caminho).toBe('nosniff');
        expect(h['x-frame-options'], caminho).toBe('SAMEORIGIN');
        expect(h['referrer-policy'], caminho).toBe('strict-origin-when-cross-origin');
        expect(h['permissions-policy'], caminho).toContain('camera=()');
        // server_tokens off: sem versão do nginx.
        expect(h.server, caminho).toBe('nginx');
    }
});

test('cookie de sessão só por HTTP, com SameSite', async ({ request }) => {
    const res = await request.get('/');
    const sessao = res.headersArray().find((h) => h.name.toLowerCase() === 'set-cookie' && h.value.startsWith('security-monitor-session='));

    expect(sessao, 'cookie security-monitor-session').toBeTruthy();
    expect(sessao.value.toLowerCase()).toContain('httponly');
    expect(sessao.value.toLowerCase()).toContain('samesite=lax');
});

test('arquivos internos não saem pelo nginx', async ({ request }) => {
    expect((await request.get('/.env')).status()).toBe(403);
    expect((await request.get('/.git/config')).status()).toBe(403);
    // Só o front controller executa PHP.
    expect((await request.get('/info.php')).status()).toBe(404);
    expect((await request.get('/storage/../.env')).status()).not.toBe(200);
});

test('áreas logadas mandam para o login certo', async ({ request }) => {
    for (const [caminho, destino] of [['/conta', '/login'], ['/alertas', '/login'], ['/alertas/noticias', '/login'], ['/alertas/integracao', '/login'], ['/alertas/notificadores', '/login'], ['/alertas/github', '/login'], ['/admin', '/admin/login']]) {
        const res = await request.get(caminho, { maxRedirects: 0 });
        expect(res.status(), caminho).toBe(302);
        expect(new URL(res.headers().location).pathname, caminho).toBe(destino);
    }
});

test('página inexistente dá 404', async ({ request }) => {
    expect((await request.get('/nao-existe')).status()).toBe(404);
    expect((await request.get('/materia/00000000-0000-0000-0000-000000000000')).status()).toBe(404);
});

test('o robô da busca (Referer sem a barra final) leva 403 antes do PHP', async ({ request }) => {
    const busca = '/?q=' + encodeURIComponent('victim_sectors:defense criticity:medium');

    expect((await request.get(busca, { headers: { Referer: 'https://example.com' } })).status()).toBe(403);
    // Navegador de verdade manda a origem com a barra, ou o endereço inteiro.
    expect((await request.get(busca, { headers: { Referer: 'https://example.com/' } })).status()).toBe(200);
    expect((await request.get(busca, { headers: { Referer: 'https://example.com/?q=ransomware' } })).status()).toBe(200);
    // Sem busca, o mesmo Referer passa.
    expect((await request.get('/', { headers: { Referer: 'https://example.com' } })).status()).toBe(200);
});

test('a CSP só deixa rodar o JavaScript do site, com o nonce da página', async ({ page }) => {
    const bloqueados = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error' && /Content Security Policy/i.test(msg.text())) bloqueados.push(msg.text());
    });

    const res = await page.goto('/sobre');
    const csp = res.headers()['content-security-policy'];

    expect(csp).toMatch(/script-src 'self' 'nonce-[A-Za-z0-9]+' https:\/\/challenges\.cloudflare\.com/);
    expect(csp).toContain("object-src 'none'");
    // O JavaScript do build roda: o token do tempo na página está lá e nenhum recurso foi barrado.
    await expect(page.locator('meta[name="page-view"]')).toHaveCount(1);
    expect(bloqueados).toEqual([]);

    // Um <script> injetado sem o nonce não roda.
    const rodou = await page.evaluate(() => {
        window.__xss = false;
        const s = document.createElement('script');
        s.textContent = 'window.__xss = true';
        document.body.appendChild(s);
        return window.__xss;
    });
    expect(rodou).toBe(false);
});
