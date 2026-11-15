// Prazo do navegador do Radar de domínios (docker/browser/run.sh).
//
// O run.sh aplica o prazo de cada rodada no CONTAINER: `run -d`, `docker wait`
// com prazo, `docker stop` e `docker kill`. Aqui o navegador é trocado por um
// container da mesma imagem que ignora o SIGTERM (o node é o PID 1 e só anota o
// sinal), e ele tem de morrer no prazo, sem sobrar container. O run.sh é o de
// verdade; o compose tem só o serviço `browser`, sem rede (network_mode: none).
//
//   docker build -t secmon/browser:ci docker/browser
//   BROWSER_IMAGE=secmon/browser:ci node --test docker/browser/test/prazo.test.mjs
//
// Não precisa de internet.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

const IMAGE = process.env.BROWSER_IMAGE || 'secmon/browser:ci';
const RUN = path.resolve(import.meta.dirname, '..', 'run.sh');
const PRAZO = 4;
const PARADA = 2;

// Os dois "navegadores": pegam o lote da fila, como o capture.mjs, e então...
// ...ignoram o SIGTERM e não saem nunca;
const IGNORA_SIGTERM = `
    process.on('SIGTERM', () => console.log('SIGTERM ignorado'));
    require('fs').rmSync('/work/inbox/lote.json', { force: true });
    console.log('trabalhando');
    setInterval(() => {}, 1e9);`;
// ...ou terminam o trabalho e saem.
const TERMINA = `
    require('fs').rmSync('/work/inbox/lote.json', { force: true });
    console.log('pronto');`;

let casos = 0;

async function preparar(script, prazo = PRAZO) {
    const raiz = await fs.mkdtemp(path.join(os.tmpdir(), 'secmon-prazo-'));
    const troca = path.join(raiz, 'troca');
    for (const sub of ['inbox', 'work', 'outbox', 'shots']) {
        await fs.mkdir(path.join(troca, sub), { recursive: true });
    }
    await fs.writeFile(path.join(troca, 'inbox', 'lote.json'), '{}');
    // As travas do serviço `browser` do docker-compose.prod.yml, menos a rede. O usuário
    // é o dono da pasta de troca, como em produção (UID e GID do app).
    const compose = [
        'services:',
        '  browser:',
        `    image: ${IMAGE}`,
        '    pull_policy: never',
        `    user: "${process.getuid()}:${process.getgid()}"`,
        '    network_mode: none',
        '    read_only: true',
        '    cap_drop: [ALL]',
        '    security_opt: ["no-new-privileges:true"]',
        '    volumes:',
        `      - ${troca}:/work`,
        `    entrypoint: ${JSON.stringify(['node', '-e', script])}`,
        '',
    ].join('\n');
    await fs.writeFile(path.join(raiz, 'compose.yml'), compose);
    casos += 1;
    const projeto = `secmon-teste-prazo-${process.pid}-${casos}`;
    const env = {
        ...process.env,
        COMPOSE_FILE: path.join(raiz, 'compose.yml'),
        COMPOSE_PROJECT_NAME: projeto,
        SECMON_LOOKALIKE_DIR: troca,
        SECMON_BROWSER_PRAZO: String(prazo),
        SECMON_BROWSER_PARADA: String(PARADA),
    };
    return { raiz, troca, projeto, env };
}

function containers(projeto, ...filtros) {
    const r = spawnSync('docker', ['ps', '-aq', '--filter', `label=com.docker.compose.project=${projeto}`, ...filtros], { encoding: 'utf8' });
    return r.stdout.trim().split('\n').filter(Boolean);
}

async function limpar(caso) {
    const sobra = containers(caso.projeto);
    if (sobra.length) spawnSync('docker', ['rm', '-f', ...sobra]);
    await fs.rm(caso.raiz, { recursive: true, force: true });
}

function rodar(caso) {
    const inicio = Date.now();
    const r = spawnSync('bash', [RUN], { env: caso.env, encoding: 'utf8', timeout: 120_000 });
    return { ...r, saida: `${r.stdout}${r.stderr}`, segundos: (Date.now() - inicio) / 1000 };
}

test('container que ignora o SIGTERM morre no prazo e não fica para trás', async () => {
    const caso = await preparar(IGNORA_SIGTERM);
    try {
        const r = rodar(caso);
        assert.equal(r.status, 0, r.saida);
        assert.match(r.saida, new RegExp(`passou do prazo de ${PRAZO} s`), r.saida);
        // O stop mandou o SIGTERM, ele não bastou, e o SIGKILL veio depois da parada.
        assert.match(r.saida, /SIGTERM ignorado/, r.saida);
        assert.ok(r.segundos >= PRAZO, `voltou em ${r.segundos} s, antes do prazo`);
        assert.ok(r.segundos < PRAZO + PARADA + 30, `levou ${r.segundos} s`);
        assert.deepEqual(containers(caso.projeto), [], 'sobrou container');
    } finally {
        await limpar(caso);
    }
});

test('rodada que termina sozinha não espera o prazo e não deixa container', async () => {
    const caso = await preparar(TERMINA, 600);
    try {
        const r = rodar(caso);
        assert.equal(r.status, 0, r.saida);
        assert.doesNotMatch(r.saida, /passou do prazo|saiu com erro/, r.saida);
        assert.match(r.saida, /pronto/, r.saida);   // a saída do navegador continua no log
        assert.ok(r.segundos < 60, `levou ${r.segundos} s`);
        assert.deepEqual(containers(caso.projeto), [], 'sobrou container');
    } finally {
        await limpar(caso);
    }
});

test('o sinal que o systemd manda ao run.sh (TimeoutStartSec) também derruba o container', async () => {
    const caso = await preparar(IGNORA_SIGTERM, 600);
    try {
        // Grupo de processos próprio: o systemd manda o SIGTERM a todos os processos da unidade.
        const filho = spawn('bash', [RUN], { env: caso.env, detached: true, stdio: ['ignore', 'pipe', 'pipe'] });
        let saida = '';
        filho.stdout.on('data', (d) => { saida += d; });
        filho.stderr.on('data', (d) => { saida += d; });
        const fim = new Promise((resolve) => filho.on('exit', (code) => resolve(code)));

        const limite = Date.now() + 60_000;
        while (!/trabalhando/.test(saida) && Date.now() < limite) {
            await new Promise((resolve) => setTimeout(resolve, 200));
        }
        assert.equal(containers(caso.projeto, '--filter', 'status=running').length, 1, saida);

        const inicio = Date.now();
        process.kill(-filho.pid, 'SIGTERM');
        const codigo = await fim;
        const segundos = (Date.now() - inicio) / 1000;
        assert.equal(codigo, 143, saida);
        assert.match(saida, /sinal recebido: derrubando o navegador/, saida);
        assert.ok(segundos < PARADA + 20, `levou ${segundos} s depois do sinal`);
        assert.deepEqual(containers(caso.projeto), [], 'sobrou container');
    } finally {
        await limpar(caso);
    }
});

test('container que ficou de uma vez anterior é derrubado antes da próxima rodada', async () => {
    const caso = await preparar(IGNORA_SIGTERM);
    try {
        // Como se o run.sh tivesse morrido com SIGKILL no meio de uma rodada.
        const velho = spawnSync('docker', ['compose', 'run', '-d', 'browser'], { env: caso.env, encoding: 'utf8' });
        assert.equal(velho.status, 0, velho.stderr);
        assert.equal(containers(caso.projeto).length, 1);
        await fs.rm(path.join(caso.troca, 'inbox', 'lote.json'), { force: true });

        const r = rodar(caso);
        assert.equal(r.status, 0, r.saida);
        assert.match(r.saida, /ficou de antes/, r.saida);
        assert.deepEqual(containers(caso.projeto), [], 'sobrou container');
    } finally {
        await limpar(caso);
    }
});
