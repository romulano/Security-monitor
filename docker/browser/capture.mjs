// Navegador do Radar de domínios do Security Monitor.
//
// Sobe, lê os pedidos de /work/inbox (escritos pelo BrowserQueue do Laravel),
// atende cada um e sai. Não há rede entre ele e o app: só a pasta de troca.
//
// Dois tipos de pedido:
// - "lookalikes": cada domínio parecido é aberto em três aparelhos ao mesmo
//   tempo (Chrome no Windows, Android, iPhone), com dois prints em cada um: ao
//   acessar e depois de 10 s na página, rolando para baixo (página que carrega
//   o golpe aos poucos aparece no segundo). Print igual ao primeiro é apagado.
// - "reference": 3 páginas do site do próprio cliente nos mesmos aparelhos,
//   1 vez por mês, para comparar com os achados.
//
// Toda conexão do Chromium passa pelo proxy abaixo, que resolve o nome, recusa
// IP interno (10.x, 127.x, 169.254.x, a rede do Docker...) e conecta no IP que
// ele mesmo validou. Assim nem a página nem um DNS que muda de resposta no
// meio do caminho (rebinding) levam o navegador para dentro do servidor. Nos
// pedidos "lookalikes", os domínios do cliente também são recusados: o site
// dele nunca recebe requisição daqui, nem quando a página falsa puxa o logo de lá.
// Os endereços do próprio Security Monitor (o IP público do servidor e os de saída) vêm
// em cada pedido e também são recusados: pelo IP, sem passar pela Cloudflare,
// a conexão cairia na borda que serve os sites de todos os stacks do servidor.

import { chromium } from 'playwright-core';
import crypto from 'node:crypto';
import dns from 'node:dns/promises';
import fs from 'node:fs/promises';
import http from 'node:http';
import net from 'node:net';
import path from 'node:path';

const WORK = process.env.WORK_DIR || '/work';
const NAV_MS = 20_000;
const STAY_MS = Number(process.env.STAY_MS || 10_000);
const DEVICE_MS = 75_000;
const RUN_MS = Number(process.env.RUN_MS || 40 * 60_000);

const started = Date.now();
const log = (...args) => console.log(new Date().toISOString(), ...args);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// ---------------------------------------------------------------- trava de rede

const internal = new net.BlockList();
for (const [address, prefix] of [
    ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12],
    ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
    ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
]) internal.addSubnet(address, prefix, 'ipv4');
// IPv4 mapeado (::ffff:10.0.0.1) já cai nas regras IPv4 acima; uma regra ::ffff:0:0/96
// aqui bloquearia todo IPv4. O NAT64 (64:ff9b::) esconde o IPv4 e vai inteiro.
// As outras faixas são as mesmas do IpGuard do app: o IPv6 que leva um IPv4 dentro
// (IPv4-compatível, NAT64 de uso local, Teredo, 6to4) e as de uso especial do IANA.
for (const [address, prefix] of [
    ['::', 128], ['::1', 128], ['64:ff9b::', 96], ['fc00::', 7], ['fe80::', 10], ['ff00::', 8], ['2001:db8::', 32],
    ['::', 96], ['64:ff9b:1::', 48], ['2001::', 32], ['2002::', 16],
    ['100::', 64], ['2001:2::', 48], ['2001:10::', 28], ['2001:20::', 28], ['3fff::', 20], ['5f00::', 16],
]) internal.addSubnet(address, prefix, 'ipv6');

// Todo IPv6 de site fica no espaço global (2000::/3); fora dele só há faixa reservada
// ou especial. O IPv4 escrito como IPv6 (::ffff:…) também fica de fora: nenhum site é
// aberto assim, e o interno já foi recusado pelas regras IPv4.
const globalV6 = new net.BlockList();
globalV6.addSubnet('2000::', 3, 'ipv6');

// Endereços do próprio Security Monitor que o pedido em curso traz (IP ou faixa). O container não
// tem .env: a lista vem do app em cada lote. Valor torto é ignorado.
let secmon = new net.BlockList();
function addressList(entries) {
    const list = new net.BlockList();
    for (const entry of Array.isArray(entries) ? entries : []) {
        const [address, bits] = String(entry).trim().split('/');
        const family = net.isIP(address);
        if (!family) continue;
        const type = family === 6 ? 'ipv6' : 'ipv4';
        try {
            if (bits === undefined) list.addAddress(address, type);
            else list.addSubnet(address, Number(bits), type);
        } catch {}
    }
    return list;
}

// Domínios do cliente que o pedido em curso proíbe (vazio no pedido "reference").
let ownDomains = [];
const isOwn = (host) => {
    const h = String(host || '').toLowerCase().replace(/\.$/, '');
    return ownDomains.some((own) => h === own || h.endsWith('.' + own));
};

async function publicAddress(host) {
    const name = String(host).replace(/^\[|\]$/g, '');
    const addresses = net.isIP(name) ? [{ address: name, family: net.isIP(name) }] : await dns.lookup(name, { all: true, verbatim: true });

    if (!addresses.length) throw new Error('não resolve');

    for (const { address, family } of addresses) {
        const type = family === 6 ? 'ipv6' : 'ipv4';
        if (internal.check(address, type)) throw new Error(`${name} → ${address} é endereço interno`);
        if (secmon.check(address, type)) throw new Error(`${name} → ${address} é endereço do próprio Security Monitor`);
        if (family === 6 && !globalV6.check(address, 'ipv6')) throw new Error(`${name} → ${address} é endereço reservado`);
    }

    return (addresses.find((a) => a.family === 4) || addresses[0]).address;
}

function splitHostPort(value) {
    const match = /^\[?([^\]]+?)\]?:(\d+)$/.exec(value || '');
    return match ? [match[1], Number(match[2])] : [value, 443];
}

// Bloqueios do proxy no domínio em curso. O pedido que nasce de um redirect
// HTTP não passa pelo page.route do Playwright: é aqui que a ida ao site do
// cliente aparece nesse caso.
let refused = [];
const refuse = (target, error) => refused.push({ target, reason: error.message, own: error.message === 'domínio do cliente' });

const proxy = http.createServer(async (req, res) => {
    // HTTP sem TLS: o Chromium manda a URL inteira para o proxy.
    try {
        const url = new URL(req.url);

        if (url.protocol !== 'http:' || (url.port && url.port !== '80')) throw new Error('só a porta 80');
        if (isOwn(url.hostname)) throw new Error('domínio do cliente');

        const ip = await publicAddress(url.hostname);
        const headers = { ...req.headers, host: url.host };
        delete headers['proxy-connection'];
        delete headers['proxy-authorization'];

        const upstream = http.request({ host: ip, port: 80, method: req.method, path: url.pathname + url.search, headers, timeout: NAV_MS }, (response) => {
            res.writeHead(response.statusCode || 502, response.headers);
            response.pipe(res);
        });
        upstream.on('timeout', () => upstream.destroy());
        upstream.on('error', () => {
            if (!res.headersSent) res.writeHead(502);
            res.end();
        });
        req.pipe(upstream);
    } catch (error) {
        refuse(req.url, error);
        res.writeHead(403, { 'x-secmon-recusado': '1' });
        res.end();
    }
});

proxy.on('connect', async (req, socket, head) => {
    // HTTPS: túnel até o IP já validado; o TLS continua de ponta a ponta com o nome original.
    const [host, port] = splitHostPort(req.url);
    socket.on('error', () => {});

    try {
        if (port !== 443) throw new Error('só a porta 443');
        if (isOwn(host)) throw new Error('domínio do cliente');

        const ip = await publicAddress(host);
        const upstream = net.connect(443, ip, () => {
            socket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
            if (head?.length) upstream.write(head);
            upstream.pipe(socket);
            socket.pipe(upstream);
        });
        upstream.setTimeout(DEVICE_MS, () => upstream.destroy());
        upstream.on('error', () => socket.destroy());
        socket.on('close', () => upstream.destroy());
    } catch (error) {
        refuse(`https://${host}/`, error);
        socket.end('HTTP/1.1 403 Forbidden\r\n\r\n');
    }
});

// ---------------------------------------------------------------- aparelhos

function devices(major) {
    return {
        // Navegador comum: Chrome no Windows.
        desktop: {
            viewport: { width: 1280, height: 800 },
            deviceScaleFactor: 1,
            userAgent: `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${major}.0.0.0 Safari/537.36`,
        },
        android: {
            viewport: { width: 412, height: 915 },
            deviceScaleFactor: 2,
            isMobile: true,
            hasTouch: true,
            userAgent: `Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${major}.0.0.0 Mobile Safari/537.36`,
        },
        // O motor continua sendo o Chromium; o que o site vê é o User-Agent e a tela do iPhone.
        iphone: {
            viewport: { width: 390, height: 844 },
            deviceScaleFactor: 2,
            isMobile: true,
            hasTouch: true,
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1',
        },
    };
}

// O que a página pede e mostra. Roda dentro de cada frame (formulário em iframe também conta).
function inspect() {
    const text = (document.body?.innerText || '').replace(/\s+/g, ' ').trim();
    const fields = [...document.querySelectorAll('input, select, textarea')].filter((el) => el.type !== 'hidden' && el.getClientRects().length > 0);
    const describe = (el) => [el.name, el.id, el.placeholder, el.getAttribute('autocomplete'), el.getAttribute('aria-label'), el.labels?.[0]?.innerText]
        .filter(Boolean).join(' ').toLowerCase();
    const count = (test) => fields.filter(test).length;
    const links = [...document.querySelectorAll('a[href]')].map((a) => {
        try { return new URL(a.href, location.href).hostname; } catch { return null; }
    }).filter(Boolean);

    return {
        title: document.title || '',
        text: text.slice(0, 4000),
        text_length: text.length,
        images: document.images.length,
        links: [...new Set(links)].slice(0, 60),
        forms: {
            inputs: fields.length,
            password: count((el) => el.type === 'password'),
            email: count((el) => el.type === 'email' || /e-?mail/.test(describe(el))),
            card: count((el) => /cc-(number|csc|exp)|card|cart[aã]o|cvv|cvc|validade/.test(describe(el))),
            cpf: count((el) => /\bcpf\b/.test(describe(el))),
            pix: count((el) => /\bpix\b/.test(describe(el))) + (/pix copia e cola|chave pix/i.test(text) ? 1 : 0),
        },
    };
}

function merge(into, found) {
    into.title ||= found.title;
    if (found.text_length > (into.text_length || 0)) {
        into.text = found.text;
        into.text_length = found.text_length;
    }
    into.images = Math.max(into.images || 0, found.images);
    into.links = [...new Set([...(into.links || []), ...found.links])].slice(0, 60);
    into.forms ||= {};
    for (const [key, value] of Object.entries(found.forms)) into.forms[key] = Math.max(into.forms[key] || 0, value);
}

// ---------------------------------------------------------------- comparação de imagens

// Página sem rede onde as imagens são comparadas num canvas: miniatura em tons de
// cinza (diferença média) e "difference hash" 16x16 (estrutura).
let comparer = null;

async function fingerprint(file) {
    const data = (await fs.readFile(path.join(WORK, 'shots', file))).toString('base64');

    return comparer.evaluate(async (src) => {
        const img = new Image();
        img.src = src;
        await img.decode();
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        const gray = (w, h) => {
            canvas.width = w;
            canvas.height = h;
            ctx.drawImage(img, 0, 0, w, h);
            const px = ctx.getImageData(0, 0, w, h).data;
            const out = [];
            for (let i = 0; i < px.length; i += 4) out.push(0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2]);
            return out;
        };
        const thumb = gray(48, 30);
        const mean = thumb.reduce((a, b) => a + b, 0) / thumb.length;
        const variance = thumb.reduce((a, b) => a + (b - mean) ** 2, 0) / thumb.length;
        const small = gray(17, 16);
        const bits = [];
        for (let y = 0; y < 16; y++) for (let x = 0; x < 16; x++) bits.push(small[y * 17 + x] > small[y * 17 + x + 1] ? 1 : 0);
        return { thumb, bits, variance };
    }, `data:image/jpeg;base64,${data}`);
}

/** 0 a 100: quanto duas imagens se parecem. Nulo se uma delas é praticamente lisa (tela branca não "parece" com nada). */
function similarity(a, b) {
    if (!a || !b || a.variance < 25 || b.variance < 25) return null;
    let diff = 0;
    for (let i = 0; i < a.thumb.length; i++) diff += Math.abs(a.thumb[i] - b.thumb[i]);
    let ham = 0;
    for (let i = 0; i < a.bits.length; i++) ham += a.bits[i] !== b.bits[i] ? 1 : 0;
    const pixel = 1 - diff / a.thumb.length / 255;
    const shape = 1 - ham / a.bits.length;
    return Math.round(100 * Math.min(pixel, shape));
}

/** Os dois prints do mesmo aparelho são a mesma tela? (rolagem e animação pequenas não contam) */
function sameShot(a, b) {
    if (!a || !b) return false;
    let diff = 0;
    for (let i = 0; i < a.thumb.length; i++) diff += Math.abs(a.thumb[i] - b.thumb[i]);
    let ham = 0;
    for (let i = 0; i < a.bits.length; i++) ham += a.bits[i] !== b.bits[i] ? 1 : 0;
    return diff / a.thumb.length < 4 && ham <= 12;
}

// ---------------------------------------------------------------- visitas

async function openPage(browser, device, opts) {
    const context = await browser.newContext({
        ...device,
        locale: 'pt-BR',
        timezoneId: 'America/Sao_Paulo',
        acceptDownloads: false,
        ...(opts.ignoreHTTPSErrors ? { ignoreHTTPSErrors: true } : {}),
        serviceWorkers: 'block',
        permissions: [],
        ...(opts.userAgentSuffix ? { userAgent: `${device.userAgent} ${opts.userAgentSuffix}` } : {}),
    });
    const page = await context.newPage();
    page.setDefaultTimeout(NAV_MS);

    return { context, page };
}

async function goto(nextPage, host, ownNav) {
    let response = null;
    let error = null;

    for (const url of [`https://${host}/`, `http://${host}/`]) {
        // Aba nova a cada tentativa: depois de um erro (TLS, por exemplo) a página de erro do Chromium
        // fica na aba e a navegação seguinte, no http://, é "interrompida" por ela.
        const page = await nextPage();

        try {
            const reply = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: NAV_MS });
            // O proxy recusou (endereço interno, domínio do cliente): isso não é uma página do site.
            if (reply.headers()['x-secmon-recusado']) throw new Error('recusado pelo proxy');
            response = reply;
            error = null;
            break;
        } catch (e) {
            error = e;
            if (ownNav()) break;
        }
    }

    return { response, error };
}

/** Um aparelho num domínio do Radar: dois prints, o que a página pede e o que ela tentou buscar no cliente. */
async function visitDeviceOnce(browser, kind, device, item, batch, refShots, hosts, lenient) {
    const out = { ok: false, own_requests: [] };
    const { context, page: firstPage } = await openPage(browser, device, { ignoreHTTPSErrors: lenient });
    let page = firstPage;
    let tabs = 0;
    const nextPage = async () => {
        if (tabs++ === 0) return page;
        const old = page;
        page = await context.newPage();
        page.setDefaultTimeout(NAV_MS);
        await old.close().catch(() => {});
        return page;
    };

    try {
        context.on('request', (request) => {
            try { hosts.add(new URL(request.url()).hostname); } catch {}
        });

        await context.route('**/*', (route) => {
            const request = route.request();
            let url;
            try { url = new URL(request.url()); } catch { return route.abort(); }

            if (isOwn(url.hostname)) {
                const navigation = request.isNavigationRequest() && request.frame() === page.mainFrame();
                if (navigation) out.own_navigation ||= request.url().slice(0, 2000);
                if (out.own_requests.length < 30) out.own_requests.push({ url: request.url().slice(0, 500), type: request.resourceType(), navigation });
                return route.abort('blockedbyclient');
            }

            return route.continue();
        });

        const { response, error } = await goto(nextPage, item.host, () => out.own_navigation);

        if (!response) {
            out.error = String(error?.message || 'sem resposta').split('\n')[0].replace(/^page\.goto: /, '').slice(0, 300);
            return out;
        }

        out.ok = true;
        out.status = response.status();
        await page.waitForLoadState('load', { timeout: 8_000 }).catch(() => {});

        // Print 1: o que aparece ao acessar.
        const first = path.join(batch, `${item.id}-${kind}-1.jpg`);
        await page.screenshot({ path: path.join(WORK, 'shots', first), type: 'jpeg', quality: 70, timeout: 10_000 }).then(() => { out.first = first; }).catch(() => {});

        // 10 s na página, rolando para baixo: conteúdo que carrega aos poucos (ou só depois de um tempo) aparece.
        const until = Date.now() + STAY_MS;
        while (Date.now() < until) {
            await page.evaluate(() => window.scrollBy(0, Math.round(window.innerHeight * 0.7))).catch(() => {});
            await page.waitForTimeout(1_000);
        }
        await page.evaluate(() => window.scrollTo(0, 0)).catch(() => {});
        await page.waitForTimeout(700);

        // Print 2, do topo de novo: dá para comparar com o primeiro.
        const second = path.join(batch, `${item.id}-${kind}-2.jpg`);
        await page.screenshot({ path: path.join(WORK, 'shots', second), type: 'jpeg', quality: 70, timeout: 10_000 }).then(() => { out.second = second; }).catch(() => {});

        out.final_url = page.url().slice(0, 2000);
        const redirects = [];
        for (let r = response.request().redirectedFrom(); r; r = r.redirectedFrom()) redirects.unshift(r.url().slice(0, 500));
        out.redirects = redirects;
        out.html_sha256 = crypto.createHash('sha256').update(await page.content().catch(() => '')).digest('hex');

        const found = {};
        for (const frame of page.frames()) {
            const data = await frame.evaluate(inspect).catch(() => null);
            if (data) merge(found, data);
        }
        Object.assign(out, found);
    } finally {
        await context.close().catch(() => {});
    }

    // Igual ao primeiro: fica um print só (para a tela e para a IA).
    const prints = { first: out.first ? await fingerprint(out.first).catch(() => null) : null, second: out.second ? await fingerprint(out.second).catch(() => null) : null };
    out.changed = Boolean(prints.first && prints.second && !sameShot(prints.first, prints.second));

    if (out.second && !out.changed) {
        await fs.unlink(path.join(WORK, 'shots', out.second)).catch(() => {});
        out.second = null;
    }

    // Parecido com qual página do cliente (no mesmo aparelho)?
    const latest = prints.second && out.changed ? prints.second : prints.first;
    let best = null;
    for (const [index, ref] of (refShots || []).entries()) {
        const score = similarity(latest, await fingerprint(ref).catch(() => null));
        if (score !== null && (!best || score > best.score)) best = { page: index, score };
    }
    out.similar = best;
    out.fingerprint = latest;

    return out;
}

/**
 * Certificado vencido, de outro nome ou sem cadeia: o navegador do visitante mostraria um aviso, mas
 * quem clica em "avançar" vê a página. Se a visita rigorosa falhou só por isso, abre de novo sem
 * checar o certificado e anota o motivo (a página existe e pode ser golpe).
 */
async function visitDevice(browser, kind, device, item, batch, refShots, hosts) {
    const strict = await visitDeviceOnce(browser, kind, device, item, batch, refShots, hosts, false);
    const cert = /net::ERR_CERT_[A-Z_]+/.exec(strict.error || '')?.[0];
    if (strict.ok || !cert) return strict;

    const lenient = await visitDeviceOnce(browser, kind, device, item, batch, refShots, hosts, true);
    if (!lenient.ok) return strict;

    lenient.certificate_error = cert;
    return lenient;
}

async function visitLookalike(browser, devs, item, batch, reference) {
    const result = { id: item.id, ok: false, captured_at: new Date().toISOString(), devices: {} };
    const hosts = new Set();
    const t0 = Date.now();
    refused = [];

    const kinds = Object.keys(devs);
    const outs = await Promise.all(kinds.map((kind) => Promise.race([
        visitDevice(browser, kind, devs[kind], item, batch, reference?.[kind], hosts),
        sleep(DEVICE_MS).then(() => ({ ok: false, error: 'a página demorou demais', own_requests: [] })),
    ]).catch((e) => ({ ok: false, error: String(e.message).slice(0, 300), own_requests: [] }))));

    kinds.forEach((kind, i) => { result.devices[kind] = outs[i]; });

    // Android e iPhone mostraram a mesma tela? (a IA recebe uma só)
    const [android, iphone] = [result.devices.android, result.devices.iphone];
    if (android?.fingerprint && iphone?.fingerprint) {
        result.mobile_same = (similarity(android.fingerprint, iphone.fingerprint) ?? 0) >= 85;
    }

    // Resumo do domínio: o computador manda; os celulares completam o que ele não viu.
    const desktop = result.devices.desktop || {};
    const main = desktop.ok ? desktop : outs.find((o) => o.ok) || desktop;
    result.ok = outs.some((o) => o.ok);
    result.status = main.status;
    result.final_url = main.final_url;
    result.redirects = main.redirects || [];
    result.html_sha256 = main.html_sha256;
    result.own_navigation = outs.map((o) => o.own_navigation).find(Boolean) || refused.find((r) => r.own && !result.ok)?.target;
    result.certificate_error = outs.map((o) => o.certificate_error).find(Boolean) || null;

    const merged = {};
    for (const o of outs) if (o.ok) merge(merged, { title: o.title || '', text: o.text || '', text_length: o.text_length || 0, images: o.images || 0, links: o.links || [], forms: o.forms || {} });
    result.title = main.title || merged.title || '';
    result.text = merged.text || '';
    result.text_length = merged.text_length || 0;
    result.images = merged.images || 0;
    result.forms = merged.forms || {};
    result.link_hosts = merged.links || [];

    const own = new Map();
    for (const o of outs) for (const r of o.own_requests || []) if (!own.has(r.url)) own.set(r.url, r);
    // Arquivo que o page.route não viu (veio de um redirect): o proxy barrou e anotou.
    if (result.ok) for (const r of refused.filter((r) => r.own)) if (!own.has(r.target)) own.set(r.target, { url: r.target, type: 'redirect', navigation: false });
    result.own_requests = [...own.values()].slice(0, 30);

    if (!result.ok) {
        const reason = refused.map((r) => r.reason).find(Boolean);
        result.error = reason
            ? reason.replace(/^getaddrinfo ENOTFOUND .*/, 'o nome não resolve no DNS')
            : String(main.error || 'sem resposta')
                .replace(/^Navigation to .* is interrupted by another navigation to "chrome-error:.*$/, 'o site não respondeu')
                .replace(/^Timeout \d+ms exceeded.*$/, 'a página demorou demais para abrir')
                .replace(/^net::ERR_CERT_[A-Z_]+.*$/, 'certificado HTTPS inválido (o navegador avisaria o visitante)')
                .slice(0, 300);
    }

    for (const kind of kinds) {
        const o = result.devices[kind];
        delete o.fingerprint;
        delete o.links;
        delete o.redirects;
        o.text = (o.text || '').slice(0, 1500);
        o.own_requests = (o.own_requests || []).length;
    }

    result.resource_hosts = [...hosts].filter((h) => !isOwn(h)).slice(0, 60);
    result.blocked = refused.slice(0, 20).map((r) => `${r.target} (${r.reason})`);
    result.ms = Date.now() - t0;

    return result;
}

// Links da página inicial do cliente que valem como referência: login, conta,
// cadastro e pagamento primeiro (é o que o golpe costuma copiar).
const PREFERRED = /(login|entrar|signin|sign-in|acesso|acessar|conta|account|minha|cadastro|cadastr|registr|checkout|pagamento|pagar|carrinho|cart|pix|cliente)/i;
const SKIP = /\.(pdf|jpe?g|png|gif|svg|webp|zip|rar|docx?|xlsx?|mp4|mp3)(\?|$)/i;

async function captureReference(browser, devs, request) {
    const site = request.site;
    const dir = String(site.dir || '').replace(/[^a-z0-9/_-]/gi, '');
    await fs.mkdir(path.join(WORK, 'shots', dir), { recursive: true });
    const opts = { userAgentSuffix: site.ua_suffix || '' };
    const result = { batch: request.batch, mode: 'reference', watch: request.watch, pages: [], captured_at: new Date().toISOString() };
    const home = new URL(site.url);
    const sameSite = (url) => url.hostname === home.hostname || (site.own || []).some((o) => url.hostname === o || url.hostname.endsWith('.' + o));

    // Página inicial no computador: de onde saem as outras duas.
    let urls = [home.href];
    {
        const { context, page } = await openPage(browser, devs.desktop, opts);
        try {
            await page.goto(home.href, { waitUntil: 'domcontentloaded', timeout: NAV_MS });
            await page.waitForLoadState('load', { timeout: 8_000 }).catch(() => {});
            const links = await page.evaluate(() => [...document.querySelectorAll('a[href]')].map((a) => ({ href: a.href, text: (a.innerText || '').trim().slice(0, 80) }))).catch(() => []);
            const seen = new Set([home.origin + home.pathname]);
            const candidates = [];
            for (const link of links) {
                let url;
                try { url = new URL(link.href); } catch { continue; }
                if (!['http:', 'https:'].includes(url.protocol) || !sameSite(url) || SKIP.test(url.pathname)) continue;
                const key = url.origin + url.pathname;
                if (seen.has(key)) continue;
                seen.add(key);
                candidates.push({ url: url.href.split('#')[0], score: PREFERRED.test(url.pathname + ' ' + link.text) ? 0 : 1 });
            }
            candidates.sort((a, b) => a.score - b.score);
            urls = [home.href, ...candidates.slice(0, Math.max(0, (site.pages || 3) - 1)).map((c) => c.url)];
        } catch (e) {
            result.error = String(e.message).split('\n')[0].slice(0, 300);
        } finally {
            await context.close().catch(() => {});
        }
    }

    for (const [index, url] of urls.entries()) {
        const entry = { url, title: '', shots: {} };
        await Promise.all(Object.entries(devs).map(async ([kind, device]) => {
            const { context, page } = await openPage(browser, device, opts);
            try {
                await page.goto(url, { waitUntil: 'domcontentloaded', timeout: NAV_MS });
                await page.waitForLoadState('load', { timeout: 8_000 }).catch(() => {});
                await page.waitForTimeout(2_500);
                if (kind === 'desktop') entry.title = (await page.title().catch(() => '')).slice(0, 300);
                const file = path.join(dir, `p${index + 1}-${kind}.jpg`);
                await page.screenshot({ path: path.join(WORK, 'shots', file), type: 'jpeg', quality: 70, timeout: 10_000 });
                entry.shots[kind] = file;
            } catch (e) {
                entry.error ||= String(e.message).split('\n')[0].replace(/^page\.goto: /, '').slice(0, 300);
            } finally {
                await context.close().catch(() => {});
            }
        }));
        result.pages.push(entry);
        log(`referência ${url}: ${Object.keys(entry.shots).join(', ') || entry.error}`);
    }

    return result;
}

// ---------------------------------------------------------------- lotes

async function main() {
    for (const dir of ['inbox', 'work', 'outbox', 'shots']) await fs.mkdir(path.join(WORK, dir), { recursive: true });

    const pending = (await fs.readdir(path.join(WORK, 'inbox'))).filter((f) => f.endsWith('.json')).sort();

    if (!pending.length) {
        log('nenhum pedido na fila');
        return;
    }

    await new Promise((resolve) => proxy.listen(0, '127.0.0.1', resolve));
    const { port } = proxy.address();

    const browser = await chromium.launch({
        headless: true,
        args: [
            `--proxy-server=http://127.0.0.1:${port}`,
            // Sem isto o Chromium fala direto com 127.0.0.1 e localhost, por fora do proxy.
            '--proxy-bypass-list=<-loopback>',
            '--disable-quic',
            '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
            '--webrtc-ip-handling-policy=disable_non_proxied_udp',
            '--disable-dev-shm-usage',
            '--disable-background-networking',
            '--disable-sync',
            '--disable-features=Translate,MediaRouter,OptimizationHints,AutofillServerCommunication',
            '--no-first-run',
        ],
    });

    const devs = devices(browser.version().split('.')[0]);
    const compareContext = await browser.newContext({ offline: true });
    comparer = await compareContext.newPage();
    await comparer.setContent('<!doctype html><title>comparar</title>');

    log(`Chromium ${browser.version()}, ${pending.length} pedido(s)`);

    // Referência tirada nesta mesma subida: os domínios do cliente já são comparados com ela.
    const freshReference = {};

    for (const name of pending) {
        // Sem tempo para um pedido inteiro: fica na fila para a próxima subida (o run.sh dá outra rodada).
        if (Date.now() - started > RUN_MS * 0.6) {
            log(`tempo da subida no fim; ${name} fica para a próxima rodada`);
            break;
        }

        const work = path.join(WORK, 'work', name);

        try {
            await fs.rename(path.join(WORK, 'inbox', name), work);
        } catch {
            continue; // outro processo pegou
        }

        const request = JSON.parse(await fs.readFile(work, 'utf8'));
        const batch = String(request.batch || '').replace(/[^a-z0-9]/g, '');
        secmon = addressList(request.block_ips);
        let answer;

        if (request.mode === 'reference') {
            ownDomains = [];
            answer = await captureReference(browser, devs, request).catch((e) => ({ batch, mode: 'reference', watch: request.watch, pages: [], error: String(e.message).slice(0, 300) }));
            freshReference[request.watch] = Object.fromEntries(Object.keys(devs).map((kind) => [kind, answer.pages.map((p) => p.shots?.[kind]).filter(Boolean)]));
        } else {
            ownDomains = (request.block_hosts || []).map((h) => String(h).toLowerCase());
            await fs.mkdir(path.join(WORK, 'shots', batch), { recursive: true });
            const results = [];

            for (const item of request.items || []) {
                if (Date.now() - started > RUN_MS) {
                    results.push({ id: item.id, skipped: true, error: 'tempo do navegador esgotado; fica para a próxima' });
                    continue;
                }

                const reference = Object.keys(request.reference || {}).length ? request.reference : freshReference[request.watch];
                const result = await visitLookalike(browser, devs, item, batch, reference)
                    .catch((e) => ({ id: item.id, ok: false, error: String(e.message).slice(0, 300), devices: {} }));
                const seen = Object.entries(result.devices || {}).map(([k, d]) => `${k}:${d.ok ? (d.changed ? '2 prints' : '1 print') : 'x'}`).join(' ');
                log(`${item.host}: ${result.ok ? `${result.status} ${result.final_url}` : result.error} · ${seen}${result.own_navigation ? ' (vai para o cliente)' : ''}`);
                results.push(result);
            }

            answer = { batch, mode: 'lookalikes', finished_at: new Date().toISOString(), results };
        }

        const out = path.join(WORK, 'outbox', `${batch}.json`);
        await fs.writeFile(out + '.tmp', JSON.stringify(answer));
        await fs.rename(out + '.tmp', out);
        await fs.unlink(work).catch(() => {});
    }

    await browser.close();
    proxy.close();
}

main().then(() => process.exit(0), (error) => {
    console.error(error);
    process.exit(1);
});
