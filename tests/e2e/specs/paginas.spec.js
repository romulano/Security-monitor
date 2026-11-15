import { test, expect } from '@playwright/test';

// Páginas públicas: abrem com 200 e o título certo, com o CSS do build aplicado
// e sem erro de JavaScript nem recurso do próprio site quebrado. O banco do CI
// nasce vazio (migrations sem seed), então é o site sem nenhum caso.
const PAGINAS = [
    ['/', /feed de ameaças/],
    ['/dashboard', /Security Monitor/],
    ['/sobre', /^Sobre · Security Monitor$/],
    ['/termos', /Security Monitor/],
    ['/privacidade', /Security Monitor/],
    ['/login', /^Entrar · Security Monitor$/],
    ['/cadastro', /Security Monitor/],
    ['/admin/login', /Security Monitor/],
];

function vigiar(page, origem) {
    const problemas = [];
    const doSite = (url) => Boolean(url) && url.startsWith(origem);

    page.on('pageerror', (erro) => problemas.push(`erro de JavaScript: ${erro.message}`));
    page.on('console', (msg) => {
        if (msg.type() === 'error' && (doSite(msg.location().url) || !msg.location().url)) problemas.push(`console: ${msg.text()}`);
    });
    page.on('requestfailed', (req) => {
        // Navegação interrompida pela próxima página não é defeito.
        if (doSite(req.url()) && req.failure()?.errorText !== 'net::ERR_ABORTED') problemas.push(`falhou: ${req.url()} (${req.failure()?.errorText})`);
    });
    page.on('response', (res) => {
        if (doSite(res.url()) && res.status() >= 400) problemas.push(`${res.status()}: ${res.url()}`);
    });

    return problemas;
}

for (const [caminho, titulo] of PAGINAS) {
    test(`${caminho} abre inteira`, async ({ page, baseURL }) => {
        const problemas = vigiar(page, new URL(baseURL).origin);

        const resposta = await page.goto(caminho);
        expect(resposta?.status()).toBe(200);
        await expect(page).toHaveTitle(titulo);
        await page.waitForLoadState('networkidle');

        const regrasCss = await page.evaluate(() => [...document.styleSheets]
            .filter((folha) => folha.href?.includes('/build/assets/'))
            .reduce((total, folha) => total + folha.cssRules.length, 0));
        expect(regrasCss, 'CSS do build (Vite) aplicado').toBeGreaterThan(0);

        expect(problemas).toEqual([]);
    });
}

test('no celular estreito, nenhuma página rola de lado', async ({ page }, testInfo) => {
    // O cabeçalho numa linha só passava da tela: a busca ficava sem largura e os links saíam pela direita (com
    // login, já a 390 px; sem login, a 320). O CI não tem conta: as públicas a 320 px conferem o mesmo cabeçalho.
    test.skip(testInfo.project.name !== 'celular', 'só no celular');
    await page.setViewportSize({ width: 320, height: 700 });

    for (const [caminho] of PAGINAS) {
        await page.goto(caminho);
        const [conteudo, tela] = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
        expect(conteudo, `${caminho} rola de lado`).toBeLessThanOrEqual(tela);
    }
});

test('no celular, a âncora não esconde o título sob o cabeçalho fixo', async ({ page }, testInfo) => {
    // O cabeçalho do celular tem duas linhas: sem compensar, o título de uma âncora (#exploits na matéria, as
    // seções das páginas legais) fica embaixo dele.
    test.skip(testInfo.project.name !== 'celular', 'só no celular');
    await page.goto('/privacidade');
    const id = await page.locator('article h2[id]').nth(2).getAttribute('id');
    await page.goto(`/privacidade#${id}`);
    await page.waitForFunction(() => window.scrollY > 0);
    const [titulo, cabecalho] = await page.evaluate((alvo) => [
        document.getElementById(alvo).getBoundingClientRect().top,
        document.querySelector('header').getBoundingClientRect().bottom,
    ], id);
    expect(titulo, `#${id} fica sob o cabeçalho`).toBeGreaterThanOrEqual(cabecalho);
});

test('o rodapé leva às páginas institucionais', async ({ page }) => {
    await page.goto('/');

    for (const [nome, caminho] of [['Sobre', '/sobre'], ['Termos', '/termos'], ['Privacidade', '/privacidade']]) {
        await page.getByRole('link', { name: nome, exact: true }).last().click();
        await expect(page).toHaveURL(new RegExp(`${caminho}$`));
        await page.goBack();
    }
});

test('login com credencial errada volta com a mensagem, sem erro', async ({ page, baseURL }) => {
    // Passa por sessão no banco, CSRF, validação e consulta de usuário.
    // A recusa volta como redirect para o formulário, não como erro HTTP.
    const problemas = vigiar(page, new URL(baseURL).origin);

    await page.goto('/login');
    await page.locator('input[name="email"]').fill('ninguem@exemplo.com.br');
    await page.locator('input[name="password"]').fill('senha-que-nao-existe');
    await page.locator('form:has(input[name="password"]) [type="submit"]').click();

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByText('Credenciais inválidas.')).toBeVisible();
    expect(problemas).toEqual([]);
});
