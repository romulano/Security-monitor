import { test, expect } from '@playwright/test';

// Contrato da API pública (/api/news), com o banco vazio do CI.

test('lista de casos', async ({ request }) => {
    const res = await request.get('/api/news');
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('application/json');

    const corpo = await res.json();
    for (const campo of ['cases', 'facets', 'total', 'page', 'per_page', 'total_pages', 'has_next', 'has_prev', 'query', 'error']) {
        expect(corpo, campo).toHaveProperty(campo);
    }
    expect(corpo.cases).toEqual([]);
    expect(corpo.total).toBe(0);
    expect(corpo.error).toBeNull();
});

test('painel', async ({ request }) => {
    const res = await request.get('/api/news/dashboard');
    expect(res.status()).toBe(200);

    const corpo = await res.json();
    for (const campo of ['total_cases', 'critical_count', 'updated_last_7d', 'window_days', 'criticity', 'activity']) {
        expect(corpo, campo).toHaveProperty(campo);
    }
    expect(Array.isArray(corpo.activity)).toBe(true);
});

test('caso inexistente dá 404', async ({ request }) => {
    expect((await request.get('/api/news/case/00000000-0000-0000-0000-000000000000')).status()).toBe(404);
});
