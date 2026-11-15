// Testes de ponta a ponta do Security Monitor. No CI rodam no container do Playwright
// (docker-compose.ci.yml, serviço e2e) contra o nginx de produção (BASE_URL=http://web).
// Local, com a stack de desenvolvimento no ar: BASE_URL=http://localhost:1515 npx playwright test
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './specs',
    fullyParallel: true,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
    use: {
        baseURL: process.env.BASE_URL || 'http://localhost:1515',
        locale: 'pt-BR',
        timezoneId: 'America/Sao_Paulo',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'computador', use: { ...devices['Desktop Chrome'] } },
        // No celular, só as páginas: cabeçalhos e API não mudam com o aparelho.
        { name: 'celular', use: { ...devices['Pixel 7'] }, testMatch: /paginas\.spec\.js/ },
    ],
});
