// Integridade da imagem do navegador do Radar de domínios (docker/browser).
//
// Sobe a imagem como em produção (sem rede para o resto do stack, raiz só de
// leitura, sem capabilities) com um pedido de verdade na pasta de troca e
// confere a resposta: o Chromium do Playwright abre e fotografa uma página
// pública nos três aparelhos, e a trava de rede recusa endereço interno e
// domínio do cliente.
//
//   docker build -t secmon/browser:ci docker/browser
//   BROWSER_IMAGE=secmon/browser:ci node --test docker/browser/test/capture.test.mjs
//
// Precisa de internet (example.com, mantido pela IANA para exemplos).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

const IMAGE = process.env.BROWSER_IMAGE || 'secmon/browser:ci';
const BATCH = '01ciintegridade';

const pedido = {
    batch: BATCH,
    mode: 'lookalikes',
    watch: 1,
    created_at: new Date().toISOString(),
    block_hosts: ['example.org'],
    reference: {},
    items: [
        { id: 1, host: 'example.com' },
        { id: 2, host: '127.0.0.1' },
        { id: 3, host: '169.254.169.254' },
        { id: 4, host: 'example.org' },
        // Certificado vencido, mantido pelo projeto badssl.com para testes de navegador.
        { id: 5, host: 'expired.badssl.com' },
    ],
};

async function rodarNavegador() {
    const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'secmon-browser-'));
    // O container roda como o usuário `node` (UID 1000), que pode não ser o dono da pasta.
    for (const sub of ['', 'inbox', 'work', 'outbox', 'shots']) {
        await fs.mkdir(path.join(dir, sub), { recursive: true });
        await fs.chmod(path.join(dir, sub), 0o777);
    }
    await fs.writeFile(path.join(dir, 'inbox', `${BATCH}.json`), JSON.stringify(pedido));

    // Mesmas travas do serviço `browser` do docker-compose.prod.yml.
    const run = spawnSync('docker', [
        'run', '--rm',
        '--read-only', '--tmpfs', '/tmp:size=256m',
        '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
        '--memory', '1536m', '--cpus', '1', '--pids-limit', '256',
        '-e', 'HOME=/tmp', '-e', 'WORK_DIR=/work', '-e', 'STAY_MS=1000',
        '-v', `${dir}:/work`,
        IMAGE,
    ], { encoding: 'utf8', timeout: 10 * 60_000 });

    return { dir, run };
}

const { dir, run } = await rodarNavegador();
const log = `${run.stdout}\n${run.stderr}`;
const resposta = await fs.readFile(path.join(dir, 'outbox', `${BATCH}.json`), 'utf8').then(JSON.parse, () => null);
const item = (id) => resposta?.results?.find((r) => r.id === id);

test('o navegador sai sem erro e responde o pedido', async () => {
    assert.equal(run.status, 0, `saída ${run.status}\n${log}`);
    assert.match(log, /Chromium \d+/, 'o Chromium do Playwright subiu');
    assert.ok(resposta, `sem resposta em outbox/\n${log}`);
    assert.equal(resposta.batch, BATCH);
    assert.equal(resposta.mode, 'lookalikes');
    assert.equal(resposta.results.length, pedido.items.length);
    assert.deepEqual(await fs.readdir(path.join(dir, 'inbox')), [], 'pedido saiu da fila');
    assert.deepEqual(await fs.readdir(path.join(dir, 'work')), [], 'nada ficou pela metade');
});

test('página pública: aberta e fotografada nos três aparelhos', async () => {
    const r = item(1);
    assert.ok(r?.ok, `example.com não abriu: ${r?.error}`);
    assert.equal(r.status, 200);
    assert.match(r.title, /Example Domain/);
    assert.match(r.final_url, /^https:\/\/example\.com\//);

    for (const aparelho of ['desktop', 'android', 'iphone']) {
        const d = r.devices[aparelho];
        assert.ok(d?.ok, `${aparelho}: ${d?.error}`);
        assert.ok(d.first, `${aparelho}: sem print`);
        const jpg = await fs.readFile(path.join(dir, 'shots', d.first));
        assert.ok(jpg.length > 1000, `${aparelho}: print vazio`);
        assert.deepEqual([...jpg.subarray(0, 3)], [0xff, 0xd8, 0xff], `${aparelho}: print não é JPEG`);
    }
});

test('trava de rede: endereço interno é recusado', () => {
    for (const id of [2, 3]) {
        const r = item(id);
        assert.equal(r?.ok, false, `${pedido.items[id - 1].host} abriu`);
        assert.match(r.error, /endereço interno/, `${pedido.items[id - 1].host}: ${r.error}`);
    }
});

test('trava de rede: o domínio do cliente nunca é acessado', () => {
    const r = item(4);
    assert.equal(r?.ok, false, 'example.org (domínio do cliente) abriu');
    assert.match(String(r.own_navigation), /example\.org/);
});

test('certificado vencido: a página abre mesmo assim e o motivo fica anotado', (t) => {
    const r = item(5);
    // Site de terceiros fora do ar não é defeito do navegador: só reprova se o certificado foi o que barrou.
    if (!r?.ok && !/certificado HTTPS inválido/.test(r?.error || '')) return t.skip(`expired.badssl.com indisponível: ${r?.error}`);

    assert.ok(r.ok, `a visita desistiu por causa do certificado: ${r.error}`);
    assert.equal(r.certificate_error, 'net::ERR_CERT_DATE_INVALID');
    assert.equal(r.devices.desktop.certificate_error, 'net::ERR_CERT_DATE_INVALID');
    assert.ok(r.devices.desktop.first, 'sem print da página de certificado vencido');
});

test.after(async () => {
    // Os prints são do UID do container; a pasta temporária fica para o sistema limpar se não der.
    await fs.rm(dir, { recursive: true, force: true }).catch(() => {});
});
