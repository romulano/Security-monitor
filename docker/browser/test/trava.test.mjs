// Trava de rede do navegador do Radar de domínios, sem internet (docker/browser).
//
// Sobe a imagem com as travas de produção e sem rede nenhuma (--network none): só o
// loopback, onde fica o proxy do próprio navegador. Os pedidos são IPs diretos, então
// nada depende de DNS nem da internet. O que se confere é a recusa do proxy, que vem
// antes de qualquer conexão: endereço interno, IPv6 que leva um IPv4 dentro, faixa
// reservada e os endereços do próprio Security Monitor que o lote traz. Um IP público fora dessas
// listas não é recusado: o proxy tenta a conexão (e não consegue, porque não há rede).
//
//   docker build -t secmon/browser:ci docker/browser
//   BROWSER_IMAGE=secmon/browser:ci node --test docker/browser/test/trava.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

const IMAGE = process.env.BROWSER_IMAGE || 'secmon/browser:ci';
const BATCH = '01citravaderede';

const pedido = {
    batch: BATCH,
    mode: 'lookalikes',
    watch: 1,
    created_at: new Date().toISOString(),
    // Domínio do cliente num TLD que nunca resolve (RFC 6761): a recusa vem antes do DNS.
    block_hosts: ['cliente.test'],
    // Como o BrowserQueue manda: o IP do servidor e a faixa IPv6 dele. Valor torto é ignorado.
    block_ips: ['203.0.113.10', '2001:db8:66:21cf::/64', 'nao-e-ip', '10.0.0.0/99'],
    reference: {},
    items: [
        { id: 1, host: '127.0.0.1' },
        { id: 2, host: '169.254.169.254' },
        { id: 3, host: '[2002:a00:1::1]' }, // 6to4 com o 10.0.0.1
        { id: 4, host: '[2001:0:4136:e378:8000:63bf:f5ff:fffe]' }, // Teredo
        { id: 5, host: '[64:ff9b:1::a00:1]' }, // NAT64 de uso local
        { id: 6, host: '[::a00:1]' }, // IPv4-compatível
        { id: 7, host: '192.88.99.1' }, // relay 6to4
        { id: 8, host: '[4000::1]' }, // fora do espaço IPv6 global
        { id: 9, host: '203.0.113.10' }, // o próprio servidor
        { id: 10, host: '[2001:db8:66:21cf::1]' }, // a faixa IPv6 do servidor
        { id: 11, host: '[::ffff:203.0.113.10]' }, // o servidor escrito como IPv6
        { id: 12, host: '8.8.8.8' }, // público e fora das listas
        { id: 13, host: 'loja.cliente.test' }, // subdomínio do cliente
    ],
};

async function rodarNavegador() {
    const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'secmon-browser-trava-'));
    // O container roda como o usuário `node` (UID 1000), que pode não ser o dono da pasta.
    for (const sub of ['', 'inbox', 'work', 'outbox', 'shots']) {
        await fs.mkdir(path.join(dir, sub), { recursive: true });
        await fs.chmod(path.join(dir, sub), 0o777);
    }
    await fs.writeFile(path.join(dir, 'inbox', `${BATCH}.json`), JSON.stringify(pedido));

    // As travas do serviço `browser` do docker-compose.prod.yml, e nenhuma rede.
    const run = spawnSync('docker', [
        'run', '--rm', '--network', 'none',
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
const host = (id) => pedido.items[id - 1].host;

test('o navegador sai sem erro e responde todos os itens, sem rede nenhuma', () => {
    assert.equal(run.status, 0, `saída ${run.status}\n${log}`);
    assert.ok(resposta, `sem resposta em outbox/\n${log}`);
    assert.equal(resposta.results.length, pedido.items.length);
});

test('endereço interno e IPv6 que leva um IPv4 dentro são recusados antes de qualquer conexão', () => {
    for (const id of [1, 2, 3, 4, 5, 6, 7]) {
        const r = item(id);
        assert.equal(r?.ok, false, `${host(id)} abriu`);
        assert.match(r.error, /endereço interno/, `${host(id)}: ${r.error}`);
    }
});

test('IPv6 fora do espaço global é recusado', () => {
    const r = item(8);
    assert.equal(r?.ok, false, `${host(8)} abriu`);
    assert.match(r.error, /endereço reservado/, `${host(8)}: ${r.error}`);
});

test('os endereços do próprio Security Monitor que o lote traz são recusados, também escritos como IPv6', () => {
    for (const id of [9, 10, 11]) {
        const r = item(id);
        assert.equal(r?.ok, false, `${host(id)} abriu`);
        assert.match(r.error, /endereço do próprio Security Monitor/, `${host(id)}: ${r.error}`);
    }
});

test('o domínio do cliente nunca é acessado', () => {
    const r = item(13);
    assert.equal(r?.ok, false, `${host(13)} abriu`);
    assert.match(String(r.own_navigation), /loja\.cliente\.test/);
});

test('IP público fora das listas não é recusado pelo proxy', () => {
    const r = item(12);
    assert.ok(r, 'sem resultado para 8.8.8.8');
    assert.deepEqual(r.blocked, [], `o proxy recusou: ${r.blocked}`);
    assert.doesNotMatch(String(r.error || ''), /endereço (interno|reservado|do próprio Security Monitor)/);
});

test.after(async () => {
    // Os prints são do UID do container; a pasta temporária fica para o sistema limpar se não der.
    await fs.rm(dir, { recursive: true, force: true }).catch(() => {});
});
