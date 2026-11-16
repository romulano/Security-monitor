<?php

namespace App\Services\Keys;

use App\Services\Alerts\Discovery\SafeFetcher;
use App\Services\Pentest\Executor\PentestMasker;
use Illuminate\Support\Sleep;

/**
 * O recon da varredura de chaves num domínio comprovado: só leitura, no domínio e nos subdomínios dele,
 * indo além da home para achar o máximo de páginas e de scripts. A posse comprovada é a
 * autorização do dono: o robots.txt (um pedido aos buscadores) não barra nada, e os caminhos que ele
 * pede para esconder viram pistas.
 *
 * - toda requisição sai pelo SafeFetcher (só GET, portas 80 e 443, IP público fixado na conexão,
 *   cada redirecionamento conferido), com o escopo do domínio: o que sai dele não é pedido;
 * - de onde vêm as páginas, uma fila por fonte lida em rodízio (o sitemap de milhares de cidades não
 *   engole as outras): os endereços extras que o dono listou (sempre primeiro), os links (`<a href>`,
 *   `<iframe>`, até 4 níveis), as URLs do domínio citadas em qualquer lugar da página ou do script
 *   (também no JSON escapado, `https:\/\/…`), o histórico do Web Archive (ArchiveUrls), os caminhos
 *   do robots.txt, os sitemaps (os do robots.txt ou o /sitemap.xml, com os índices), os caminhos de
 *   página citados nos scripts e os palpites de app (`/app/<nome>/` com os nomes de funcionalidade que
 *   aparecem, quando o site tem apps em /app/…); e os scripts: os `<script src>` e `modulepreload`
 *   das páginas, as URLs .js citadas e os pedaços que cada script importa;
 * - páginas e scripts têm filas e orçamentos próprios (até 1.000 páginas e 1.000 scripts), e cada endereço entra
 *   na fila uma vez só (o menu que toda página repete, o script que várias usam): a fila não enche de repetidos e
 *   a página nova sempre consegue pôr os links e os scripts dela;
 * - no máximo 3 páginas por formato de endereço (/perfil/123 e /perfil/456 são o mesmo formato, e
 *   /acompanhantes-sp e /acompanhantes-rj também);
 * - uma requisição a cada 0,4 s, 2 MiB por resposta, em etapas de 7 minutos (o estado vai de uma para a outra) e até 1
 *   hora no total, e a navegação para depois de
 *   6 bloqueios seguidos (403/429);
 * - nunca abre link de ação (sair, apagar, pagar, confirmar…, ou com token), endereço de API, nem
 *   arquivo binário ou de dados (.sql, .env, .zip…), e nunca envia formulário;
 * - as chaves saem do KeyDetector (o mesmo do executor), só mascaradas, com a URL, a linha e a coluna
 *   de cada lugar; o corpo nunca é guardado.
 */
class KeyCrawler
{
    public const MAX_PAGES = 1000;

    public const MAX_SCRIPTS = 1000;

    public const MAX_DEPTH = 4;

    public const MAX_HOSTS = 5;

    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Uma etapa da varredura (um job) e a varredura inteira: a de 1.000 páginas leva mais que um job aguenta, então cada
     * etapa devolve o estado e o job seguinte continua de onde ela parou (ScanSiteKeys).
     */
    public const SLICE_SECONDS = 420;

    public const MAX_SECONDS = 3600;

    /** De quanto em quanto a etapa entrega o que achou até ali (as chaves e o estado): o job grava, e a varredura que cair continua dali. */
    public const CHECKPOINT_SECONDS = 60;

    public const DELAY_MS = 400;

    /** As páginas lidas por formato de endereço. */
    public const PER_SHAPE = 3;

    public const MAX_SITEMAPS = 5;

    public const MAX_SITEMAP_URLS = 3000;

    /** Os endereços do Web Archive que entram na fila (depois do formato) e os scripts dele. */
    private const MAX_ARCHIVE_PAGES = 600;

    private const MAX_ARCHIVE_SCRIPTS = 60;

    /** As fontes de página, na ordem do rodízio (os endereços extras vêm antes de todas). */
    private const SOURCES = ['link', 'cited', 'archive', 'robots', 'sitemap', 'js'];

    /**
     * O endereço com cara de área sensível vai para a fila quente, lida a cada duas páginas, venha de que fonte vier, pela
     * pontuação: 3 para autenticação e administração (login, senha, recuperação, verificação, facial, 2FA, painel,
     * configuração, documentação de API), 1 para conta e cadastro; +1 para o que uma página de peso 3 cita ou linka (a cadeia
     * do login até a recuperação facial é seguida). No empate, o mais novo.
     */
    private const HOT_STRONG = '~(?<![a-z0-9])(login|logon|signin|sign-in|entrar|auth|oauth|autentic|sso|senha|password|passwd|redefin|reset|recover|recupera|forgot|esquec|verif|'
        .'facial|2fa|mfa|otp|admin|painel|dashboard|console|config|settings|swagger|api-docs|graphiql|debug|staging)~';

    private const HOT_WEAK = '~(?<![a-z0-9])(account|contas?(?![a-z])|profile|perfil|cadastro|register|signup|sign-up|onboarding|checkout|pagamento|payment|billing)|(^|/)apps?/~';

    /** As URLs citadas que entram na fila, os caminhos do robots.txt e os palpites de app, no total. */
    private const MAX_CITED = 1500;

    private const MAX_ROBOTS_PATHS = 100;

    /** Os palpites de app: os de nome genérico (/app/bdsm/) e os de nome sensível (/app/facial-auth/), cada um com o seu teto. */
    private const MAX_GUESSES = 20;

    private const MAX_STRONG_GUESSES = 60;

    /** Os nomes de funcionalidade guardados para os palpites (os de área sensível entram sempre). */
    private const MAX_NAMES = 1500;

    /** As URLs citadas que uma página (ou um script) põe na fila: a home que cita centenas não engole as das outras. */
    private const CITED_PER_PAGE = 80;

    /** Os caminhos de página tirados dos scripts, no total. */
    private const MAX_SCRIPT_PATHS = 300;

    /** Bloqueios seguidos (403, 429) que param a navegação: o site (ou o WAF) não quer o Security Monitor ali. */
    private const MAX_BLOCKS = 6;

    private const MAX_QUEUE = 5000;

    /** Os lugares (URL, linha e coluna) guardados por chave num nome. */
    private const MAX_SPOTS = 20;

    /**
     * 3 para autenticação e administração, 1 para conta e cadastro, 0 para o resto. A palavra vale pelo começo ("sso" não é
     * o meio de "acessorios"), e o trecho que é título de artigo (com o id na frente, ou de 5 palavras ou mais) não conta:
     * a central de ajuda fala de login e de senha em todo artigo e enchia a fila quente na frente do login de verdade.
     */
    private static function score(string $path): int
    {
        [$path, $query] = explode('?', strtolower($path), 2) + [1 => ''];
        $segments = array_filter(explode('/', $path), fn (string $one) => $one !== '' && ! preg_match('~^\d{4,}-~', $one) && substr_count($one, '-') < 4);
        $path = '/'.implode('/', $segments).'/?'.$query;

        return match (true) {
            preg_match(self::HOT_STRONG, $path) === 1 => 3,
            preg_match(self::HOT_WEAK, $path) === 1 => 1,
            default => 0,
        };
    }

    /** A seção de um endereço: o nome e o 1º trecho do caminho (https://loja.com.br/blog/x → loja.com.br/blog). */
    private static function section(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        return strtolower((string) parse_url($url, PHP_URL_HOST)).'/'.strtolower(explode('/', ltrim($path, '/'), 2)[0]);
    }

    /** O caminho citado num script que é de API, não de página: não é aberto. */
    private const API_WORDS = ['api', 'graphql', 'rest', 'rpc', 'ajax', 'webhook', 'webhooks', 'callback', 'track', 'tracking', 'event', 'events',
        'collect', 'pixel', 'beacon', 'log', 'logs', 'metrics', 'analytics', 'socket', 'ws', 'oauth', 'auth', 'token'];

    private const BINARY = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'bmp', 'tif', 'tiff', 'avif', 'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp4', 'webm', 'mp3', 'ogg', 'wav', 'avi', 'mov', 'mkv', 'flac', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'exe',
        'msi', 'dmg', 'iso', 'apk', 'bin', 'css', 'map', 'swf'];

    private const DATA = ['sql', 'csv', 'json', 'log', 'bak', 'old', 'orig', 'dump', 'db', 'sqlite', 'sqlite3', 'mdb', 'zip', 'gz', 'tgz', 'tar',
        'rar', '7z', 'bz2', 'xz', 'xml', 'yml', 'yaml', 'env', 'ini', 'conf', 'txt'];

    /**
     * As ações negadas do mapeamento (docker/pentest/executor/mapeamento.py), a palavra no caminho ou na query; sem as que,
     * nos fluxos de conta, são páginas e não ações (reset, verify, verificar, setup, checkout, carrinho, cart): a varredura
     * autorizada precisa ver a recuperação de senha e a verificação.
     */
    private const DENIED = ['sair', 'logout', 'logoff', 'signout', 'signoff', 'deslogar', 'apagar', 'excluir', 'remover', 'deletar', 'delete', 'remove',
        'destroy', 'purge', 'flush', 'truncate', 'cancelar', 'cancel', 'unsubscribe', 'descadastrar', 'confirmar', 'confirm', 'aprovar', 'approve',
        'rejeitar', 'recusar', 'reject', 'aceitar', 'accept', 'ativar', 'desativar', 'activate', 'deactivate', 'pagar', 'pay', 'comprar', 'buy', 'transferir', 'transfer', 'enviar', 'send', 'publicar', 'publish', 'unpublish', 'despublicar', 'esvaziar',
        'vote', 'follow', 'like', 'subscribe', 'install', 'uninstall', 'upgrade', 'shutdown', 'reboot', 'restart', 'kill', 'exit', 'encerrar',
        'desconectar', 'del', 'drop', 'clear', 'limpar', 'trash', 'lixeira', 'wipe', 'erase', 'add', 'adicionar', 'unfollow', 'upvote', 'downvote',
        'dislike', 'toggle', 'enable', 'disable', 'habilitar', 'desabilitar', 'bloquear', 'desbloquear', 'block', 'unblock', 'ban', 'unban', 'lock',
        'unlock', 'resend', 'reenviar', 'redeem', 'resgatar', 'restore', 'restaurar', 'rollback', 'revert', 'reverter', 'arquivar', 'suspend',
        'suspender', 'reiniciar', 'desligar', 'refund', 'estornar', 'reembolsar', 'donate', 'doar', 'rsvp'];

    private const DENIED_STEMS = ['logout', 'logoff', 'signout', 'signoff', 'deslogar', 'delete', 'deletar', 'remove', 'remover', 'excluir', 'apagar',
        'destroy', 'cancel', 'unsubscribe', 'descadastrar', 'uninstall', 'shutdown', 'desconectar', 'encerrar'];

    private const DENIED_PARTS = ['admin-post', 'wp-admin', 'log-out', 'log-off', 'sign-out', 'sign-off'];

    private const DENIED_PARAMS = ['_token', '_wpnonce', 'nonce', 'action', 'do', 'cmd', 'op', 'authenticity_token', 'csrfmiddlewaretoken', 'task',
        '_method', 'signature', 'sig', 'hmac', 'ticket', 'otp'];

    public function __construct(private SafeFetcher $fetcher, private KeyDetector $detector, private ArchiveUrls $archive) {}

    /**
     * Navega o domínio e devolve as chaves por nome, no formato do registro (KeyLedger::record). `$seeds`: os endereços
     * extras que o dono listou (caminhos ou URLs do domínio), lidos antes de tudo e fora do teto por formato.
     *
     * `$state`: o estado que a etapa anterior devolveu (nulo, começa do zero). O resultado traz `state` quando a etapa
     * acabou e ainda há o que ler: o job seguinte o passa de volta. As chaves e as contagens são as da varredura inteira.
     *
     * @param  list<string>  $seeds
     * @param  array<string, mixed>|null  $state
     * @param  ?callable(array<string, mixed>): void  $checkpoint  recebe, de minuto em minuto, o resultado até ali (com o `state` de onde continuar)
     * @return array{keys: array<string, list<array<string, mixed>>>, pages: int, scripts: int, sitemap_urls: int, requests: int, seconds: int, net_seconds: int, unanswered: int, hosts: list<string>, truncated: bool, blocked: bool, reached: bool, state: ?array<string, mixed>}
     */
    public function crawl(string $domain, array $seeds = [], ?array $state = null, ?callable $checkpoint = null): array
    {
        $domain = strtolower($domain);
        $inScope = fn (string $host) => $host === $domain || str_ends_with($host, '.'.$domain);
        // O teto do SafeFetcher conta cada salto de redirecionamento: folga para eles, os sitemaps e os robots.txt.
        $this->fetcher->startScan(2 * (self::MAX_PAGES + self::MAX_SCRIPTS) + self::MAX_SITEMAPS + 2 * self::MAX_HOSTS,
            fn (string $host, string $ip, int $port) => $inScope(strtolower($host)));
        $started = hrtime(true);
        $slice = (int) config('secmon.keys.slice_seconds', self::SLICE_SECONDS);

        if ($state !== null) {
            return $this->run($state, $domain, $inScope, $started, $slice, $checkpoint);
        }

        $s = ['pages' => ['seed' => [], 'hot' => [], 'link' => [], 'cited' => [], 'archive' => [], 'robots' => [], 'sitemap' => [], 'js' => []], 'scripts' => [],
            'source' => 0, 'archive_urls' => 0, 'cited' => 0, 'robots_paths' => 0, 'names' => [], 'roots' => [], 'guessed' => [], 'domain' => $domain,
            'queued_shapes' => [], 'queued_pages' => [], 'queued_scripts' => [], 'barred' => [], 'last_blocked' => null, 'seen' => [], 'shapes' => [], 'robots' => [], 'sitemaps' => [], 'found' => [], 'read' => 0, 'js' => 0,
            'requests' => 0, 'blocks' => 0, 'sitemap_urls' => 0, 'script_paths' => 0, 'reached' => false, 'mapped' => false, 'turn' => 0, 'elapsed' => 0];
        $s['pages']['link'][] = ["https://{$domain}/", 0];

        foreach (array_slice($seeds, 0, 100) as $seed) {
            $s['pages']['seed'][] = [str_starts_with($seed, '/') ? "https://{$domain}{$seed}" : $seed, 0];
        }

        return $this->run($s, $domain, $inScope, $started, $slice, $checkpoint);
    }

    /** Uma etapa: lê até o fim da fila, do teto ou do tempo da etapa. */
    private function run(array $s, string $domain, callable $inScope, int $started, int $slice, ?callable $checkpoint = null): array
    {
        $stop = null;
        $first = $s['requests'];
        $every = (int) config('secmon.keys.checkpoint_seconds', self::CHECKPOINT_SECONDS);
        $saved = hrtime(true);

        while (self::pending($s) > 0 || $s['scripts'] !== [] || ! $s['mapped']) {
            $now = (hrtime(true) - $started) / 1e9;

            if ($s['elapsed'] + $now > self::MAX_SECONDS) {
                $stop = 'tempo';
                break;
            }

            // A etapa acabou (e já leu alguma coisa): o estado vai para o job seguinte.
            if ($now > $slice && $s['requests'] > $first) {
                $stop = 'etapa';
                break;
            }

            // Bloqueios seguidos: a home de novo. Respondeu, é só aquela seção (o blog atrás de outro WAF), que fica de fora;
            // barrada também, o site não quer a varredura ali e ela para.
            if ($s['blocks'] >= self::MAX_BLOCKS) {
                // (Com a query, porque o SafeFetcher não pede de novo o que já leu nesta varredura.)
                // Só 403, 429 ou nenhuma resposta, duas vezes seguidas, é bloqueio: o 500 de um instante do site não para nada.
                $refused = fn (array $r) => $r['status'] === null || in_array($r['status'], [403, 429], true);

                if ($refused($this->fetch($s, "https://{$domain}/?sonda=".$s['requests'])) && $refused($this->fetch($s, "https://{$domain}/?sonda=".$s['requests']))) {
                    $stop = 'bloqueio';
                    break;
                }

                $s['barred'][self::section((string) $s['last_blocked'])] = true;
                $s['blocks'] = 0;
            }

            // Os sitemaps e o Web Archive entram depois dos endereços extras e da home (os links dela vêm primeiro).
            if (! $s['mapped'] && $s['pages']['seed'] === [] && ($s['read'] > 0 || self::pending($s) === 0)) {
                $s['mapped'] = true;
                $this->sitemaps($s, $domain, $inScope);
                $this->archived($s, $domain, $inScope);

                continue;
            }

            // De minuto em minuto, o que achou até ali: o job grava as chaves e o estado (a etapa que o deploy matar continua dali).
            if ($checkpoint !== null && (hrtime(true) - $saved) / 1e9 >= $every && $s['requests'] > $first) {
                $saved = hrtime(true);
                $checkpoint($this->result($s, $started, 'etapa'));
            }

            $pending = self::pending($s);
            $scriptTurn = $s['scripts'] !== [] && $s['js'] < self::MAX_SCRIPTS && ($pending === 0 || $s['read'] >= self::MAX_PAGES || $s['turn']++ % 2 === 1);

            if ($scriptTurn) {
                $this->script($s, array_shift($s['scripts']), $inScope);
            } elseif ($pending > 0 && $s['read'] < self::MAX_PAGES) {
                [$url, $depth, $seed] = self::nextPage($s);
                $this->page($s, $url, $depth, $inScope, $seed);
            } else {
                break;
            }
        }

        return $this->result($s, $started, $stop);
    }

    /** O resultado da varredura até aqui; com `state` (de onde continuar) quando a etapa parou e ainda há o que ler. */
    private function result(array $s, int $started, ?string $stop): array
    {
        $s['elapsed'] += (int) ceil((hrtime(true) - $started) / 1e9);
        $more = $stop === 'etapa' && $s['read'] < self::MAX_PAGES;

        return ['keys' => array_map('array_values', $s['found']), 'pages' => $s['read'], 'scripts' => $s['js'], 'sitemap_urls' => $s['sitemap_urls'],
            'archive_urls' => $s['archive_urls'], 'requests' => $s['requests'], 'hosts' => array_keys($s['robots']), 'blocked' => $stop === 'bloqueio',
            'seconds' => $s['elapsed'], 'net_seconds' => (int) round($s['net'] ?? 0), 'unanswered' => $s['unanswered'] ?? 0,
            'truncated' => ! $more && ($stop !== null || self::pending($s) > 0 || $s['scripts'] !== []), 'reached' => $s['reached'],
            // O estado vai para um jsonb: a URL tirada de uma página que não é UTF-8 não pode derrubar a gravação.
            'state' => $more ? json_decode((string) json_encode($s, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES), true) : null];
    }

    private static function pending(array $s): int
    {
        return array_sum(array_map('count', $s['pages']));
    }

    /** A próxima página: os endereços extras primeiro; depois a fila quente a cada duas vezes; depois uma de cada fonte, em rodízio. @return array{0: string, 1: int, 2: bool} */
    private static function nextPage(array &$s): array
    {
        if ($s['pages']['seed'] !== []) {
            // O endereço extra do dono conta como sensível: os scripts dele vão para a frente da fila.
            $s['current_score'] = 3;

            return [...array_shift($s['pages']['seed']), true];
        }

        if ($s['pages']['hot'] !== [] && (($s['hot_turn'] = ($s['hot_turn'] ?? 0) + 1) % 2 === 1 || self::pending($s) === count($s['pages']['hot']))) {
            // A de maior pontuação; no empate, a mais nova.
            $best = 0;

            foreach ($s['pages']['hot'] as $i => $item) {
                $top = $s['pages']['hot'][$best];
                $best = $item[2] > $top[2] || ($item[2] === $top[2] && $item[3] > $top[3]) ? $i : $best;
            }

            [$url, $depth] = $s['pages']['hot'][$best];
            array_splice($s['pages']['hot'], $best, 1);
            $s['current_score'] = self::score((string) parse_url($url, PHP_URL_PATH));

            return [$url, $depth, false];
        }

        $s['current_score'] = 0;

        for ($i = 0; $i < count(self::SOURCES); $i++) {
            $source = self::SOURCES[$s['source']++ % count(self::SOURCES)];

            if ($s['pages'][$source] !== []) {
                return [...array_shift($s['pages'][$source]), false];
            }
        }

        return ['', 0, false];
    }

    /**
     * O histórico do Web Archive: no máximo 3 endereços por formato entram na fila de páginas (os de script, na de
     * scripts), já no https e sem a porta padrão.
     */
    private function archived(array &$s, string $domain, callable $inScope): void
    {
        [$shapes, $scripts] = [[], 0];

        foreach ($this->archive->urls($domain) as $url) {
            $url = (string) preg_replace('~^https?://([^/:]+)(?::(?:80|443))?~i', 'https://$1', $url);
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

            if ($host === '' || ! $inScope($host)) {
                continue;
            }

            if (self::extension($path) === 'js') {
                if ($scripts++ < self::MAX_ARCHIVE_SCRIPTS) {
                    $this->addScript($s, $url);
                }

                continue;
            }

            $shape = self::shape($host, $path);

            if (self::skipped($path, (string) parse_url($url, PHP_URL_QUERY)) || ($shapes[$shape] = ($shapes[$shape] ?? 0) + 1) > self::PER_SHAPE) {
                continue;
            }

            $this->addPage($s, 'archive', $url, 1);

            if (++$s['archive_urls'] >= self::MAX_ARCHIVE_PAGES) {
                break;
            }
        }
    }

    /** Uma página: as chaves dela, os links (para a fila de páginas) e os scripts (para a deles). */
    private function page(array &$s, string $url, int $depth, callable $inScope, bool $seed = false): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '/') ?: '/';
        $query = (string) ($parts['query'] ?? '');
        $key = $host.$path.'?'.self::queryNames($query);
        $shape = self::shape($host, $path);

        if (isset($s['seen'][$key]) || ! $inScope($host) || self::skipped($path, $query) || (! $seed && ($s['shapes'][$shape] ?? 0) >= self::PER_SHAPE)
            || isset($s['barred'][self::section($url)])) {
            return;
        }

        $s['seen'][$key] = true;

        if (! $this->robotsFor($s, $host)) {
            return;
        }

        $response = $this->fetch($s, $url);

        // O site que não responde no https: a inicial pelo http, uma vez (o SafeFetcher segue o redirecionamento).
        if (! $response['ok'] && $s['read'] === 0 && $depth === 0 && str_starts_with($url, 'https://')) {
            $response = $this->fetch($s, 'http://'.substr($url, 8));
        }

        if (! $response['ok'] || $response['status'] === null || $response['status'] >= 400) {
            return;
        }

        $s['reached'] = true;
        $s['read']++;
        $s['shapes'][$shape] = ($s['shapes'][$shape] ?? 0) + 1;
        $final = (string) $response['url'];
        $type = strtolower((string) ($response['headers']['content-type'][0] ?? ''));

        if (str_contains($type, 'javascript')) {
            $this->collect($s['found'], strtolower((string) parse_url($final, PHP_URL_HOST)), $final, $response['body']);
            $this->fromScript($s, $final, $response['body']);

            return;
        }

        if (str_contains($type, 'html') || str_contains($type, 'json')) {
            $this->collect($s['found'], strtolower((string) parse_url($final, PHP_URL_HOST)), $final, $response['body']);
            $this->cited($s, $response['body'], $inScope);
        }

        if (! str_contains($type, 'html')) {
            return;
        }

        foreach (self::links($response['body']) as [$ref, $script]) {
            $next = self::absolute($final, $ref);

            // Cada fila com o seu teto: a de páginas cheia não deixa de fora os scripts da página (nem o contrário).
            if ($next === null) {
                continue;
            }

            if ($script || self::extension((string) parse_url($next, PHP_URL_PATH)) === 'js') {
                $this->addScript($s, $next);
            } elseif ($depth < self::MAX_DEPTH) {
                $this->addPage($s, 'link', $next, $depth + 1);
            }
        }
    }

    /** Um script: as chaves dele, os pedaços que ele importa e os caminhos de página que ele cita. */
    private function script(array &$s, string $url, callable $inScope): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '/') ?: '/';
        $key = 'js:'.self::scriptKey($url);
        $extension = self::extension($path);

        if (isset($s['seen'][$key]) || isset($s['barred'][self::section($url)]) || in_array($extension, self::BINARY, true) || in_array($extension, self::DATA, true) || ! $inScope($host) || ! $this->robotsFor($s, $host)) {
            return;
        }

        $s['seen'][$key] = true;
        $response = $this->fetch($s, $url);

        if (! $response['ok'] || $response['status'] === null || $response['status'] >= 400) {
            return;
        }

        $s['reached'] = true;
        $s['js']++;
        $final = (string) $response['url'];
        $this->collect($s['found'], strtolower((string) parse_url($final, PHP_URL_HOST)), $final, $response['body']);
        $this->fromScript($s, $final, $response['body']);
    }

    /** Os pedaços de JS que o script importa (para a fila de scripts) e os caminhos de página que ele cita (para a de páginas). */
    private function fromScript(array &$s, string $base, string $body): void
    {
        $this->cited($s, $body, fn (string $host) => $host === $s['domain'] || str_ends_with($host, '.'.$s['domain']));

        preg_match_all('~["\'`]((?:\.{1,2}/|/)[A-Za-z0-9_\-./@]{1,200}\.m?js)(?:\?[^"\'`\s]{0,100})?["\'`]~', substr($body, 0, self::MAX_BYTES), $imports);

        foreach (array_slice(array_unique($imports[1]), 0, 500) as $ref) {
            if (($next = self::absolute($base, $ref)) !== null) {
                $this->addScript($s, $next);
            }
        }

        preg_match_all('~["\'`](/[A-Za-z0-9_\-./]{2,120})["\'`]~', substr($body, 0, self::MAX_BYTES), $paths);

        foreach (array_unique($paths[1]) as $path) {
            if ($s['script_paths'] >= self::MAX_SCRIPT_PATHS || self::pending($s) >= self::MAX_QUEUE) {
                break;
            }

            if (str_starts_with($path, '//') || self::extension($path) !== '' || self::isApi($path) || ($next = self::absolute($base, $path)) === null) {
                continue;
            }

            $s['script_paths']++;
            $this->addPage($s, 'js', $next, 2);
        }
    }

    /**
     * Um script na fila, uma vez só (as páginas de um site de JS moderno repetem os mesmos cem pedaços: sem isto, a fila
     * crescia com eles e o script de uma página nova ficava no fim). O de uma página sensível (o bundle do app de
     * recuperação facial, do login) entra na frente.
     */
    private function addScript(array &$s, string $url): void
    {
        $key = self::scriptKey($url);
        $front = ($s['current_score'] ?? 0) >= 3;

        if (isset($s['queued_scripts'][$key])) {
            // Já na fila (veio antes, de uma página comum ou do Web Archive) e agora é de uma página sensível: para a frente.
            if ($front && ! isset($s['seen']['js:'.$key]) && ($at = array_search($key, array_map(self::scriptKey(...), $s['scripts']), true)) !== false) {
                array_unshift($s['scripts'], array_splice($s['scripts'], $at, 1)[0]);
            }

            return;
        }

        if (count($s['scripts']) >= self::MAX_QUEUE) {
            if (! $front) {
                return;
            }

            // Fila cheia: o da página sensível entra no lugar do último.
            unset($s['queued_scripts'][self::scriptKey((string) array_pop($s['scripts']))]);
        }

        $s['queued_scripts'][$key] = true;

        if ($front) {
            array_unshift($s['scripts'], $url);
        } else {
            $s['scripts'][] = $url;
        }
    }

    private static function scriptKey(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST)).(string) (parse_url($url, PHP_URL_PATH) ?: '/');
    }

    /**
     * As URLs do domínio citadas em qualquer lugar do texto: no HTML, num JSON embutido (também escapado, `https:\/\/…`
     * e `&quot;`), num script. As de script vão para a fila de scripts; as outras, para a de páginas.
     */
    private function cited(array &$s, string $body, callable $inScope): void
    {
        $added = 0;
        // O JSON escapado (`https:\\/\\/…`, `\\u002F`) e o HTML com entidades viram texto comum antes de procurar as URLs.
        $text = str_replace(['\\/', '&quot;', '&#x2F;', '&#47;', '\\'.'u002F'], ['/', '"', '/', '/', '/'], substr($body, 0, self::MAX_BYTES));
        preg_match_all('~https?://[A-Za-z0-9.-]{1,253}(?::(?:80|443))?/[^\s"\'<>\\\\`)]{0,500}~', $text, $m);

        foreach (array_slice(array_unique($m[0]), 0, 2000) as $url) {
            $url = rtrim(explode('#', $url, 2)[0], '.,;');
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

            if ($host === '' || ! $inScope($host)) {
                continue;
            }

            if (self::extension($path) === 'js') {
                $this->addScript($s, $url);
            } elseif ((self::score($path) > 0 || ($s['cited'] < self::MAX_CITED && $added < self::CITED_PER_PAGE)) && ! self::skipped($path, (string) parse_url($url, PHP_URL_QUERY))
                && ! self::isApi($path) && $this->addPage($s, 'cited', $url, 1)) {
                // (A de área sensível entra sempre, fora dos tetos: é a cadeia do login até a recuperação.)
                $s['cited']++;
                $added++;
            }
        }
    }

    /**
     * Uma página na fila da fonte, uma vez só; falso, ela não entrou. As fontes de volume (as citadas, o archive, o sitemap,
     * os caminhos dos scripts) já entram com no máximo 3 por formato, e os links, com 12. De passagem, as raízes de app (/app/, /apps/) e os nomes de
     * funcionalidade, o último trecho das URLs citadas, do robots.txt e dos scripts: com uma raiz, cada nome vira um
     * palpite `/<raiz>/<nome>/` (o /redefine/facial-auth citado e os apps em /app/… dão o /app/facial-auth/).
     */
    private function addPage(array &$s, string $source, string $url, int $depth): bool
    {
        if (self::pending($s) >= self::MAX_QUEUE) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $key = $host.$path.'?'.self::queryNames($query);

        // Não ocupa lugar na fila o que não seria lido: de fora do domínio, link de ação, já lido ou já na fila (cada página
        // de um site repete os links do menu e do rodapé: sem isto a fila enchia de repetidos e as páginas novas não entravam).
        if (($host !== $s['domain'] && ! str_ends_with($host, '.'.$s['domain'])) || self::skipped($path, $query) || isset($s['seen'][$key]) || isset($s['queued_pages'][$key])) {
            return false;
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $one) => $one !== ''));
        // O endereço de área sensível vai para a fila quente com a pontuação (o caminho e os nomes da query, sem os valores).
        $score = self::score('/'.implode('/', $segments).'?'.self::queryNames($query));

        // Por formato: 3 das fontes de volume e 12 dos links (a folga para os que não respondem); a de área sensível, sempre.
        if ($score < 3 && in_array($source, ['link', 'cited', 'archive', 'sitemap', 'js'], true)) {
            $shape = self::shape($host, '/'.implode('/', $segments));

            if (($s['queued_shapes'][$shape] = ($s['queued_shapes'][$shape] ?? 0) + 1) > ($source === 'link' ? 4 : 1) * self::PER_SHAPE) {
                return false;
            }
        }

        $s['queued_pages'][$key] = true;

        if ($score > 0) {
            $bonus = in_array($source, ['cited', 'link', 'js'], true) && ($s['current_score'] ?? 0) >= 3 ? 1 : 0;
            $s['pages']['hot'][] = [$url, $depth, $score + $bonus, $s['hot_seq'] = ($s['hot_seq'] ?? 0) + 1];
        } else {
            $s['pages'][$source][] = [$url, $depth];
        }

        // Os palpites de app, só com a raiz ou o nome que acabou de aparecer (e não todos os pares a cada endereço).
        $root = $host.'|/'.strtolower($segments[0] ?? '').'/';

        if (count($segments) >= 2 && in_array(strtolower($segments[0]), ['app', 'apps'], true) && ! isset($s['roots'][$root])) {
            $s['roots'][$root] = true;

            foreach (array_keys($s['names']) as $name) {
                self::guess($s, $root, (string) $name);
            }
        }

        $last = strtolower((string) end($segments));

        if (in_array($source, ['cited', 'robots', 'js'], true) && count($segments) >= 2 && preg_match('~^[a-z][a-z-]{2,24}$~', $last) && substr_count($last, '-') <= 2
            && ! isset($s['names'][$last]) && (count($s['names']) < self::MAX_NAMES || self::score('/'.$last.'/') >= 3)) {
            $s['names'][$last] = true;

            foreach (array_keys($s['roots']) as $known) {
                self::guess($s, (string) $known, $last);
            }
        }

        return true;
    }

    /** O palpite `/<raiz>/<nome>/` na fila quente, pela pontuação do nome: /app/facial-auth/ com peso 3, /app/bdsm/ com 1. */
    private static function guess(array &$s, string $root, string $name): void
    {
        [$host, $prefix] = explode('|', $root, 2);
        $strong = self::score('/'.$name.'/') >= 3;

        if (isset($s['guessed'][$root.$name]) || $name === trim($prefix, '/') || count($s['guessed']) >= self::MAX_GUESSES + self::MAX_STRONG_GUESSES
            || (! $strong && ($s['weak_guesses'] ?? 0) >= self::MAX_GUESSES)) {
            return;
        }

        $s['weak_guesses'] = ($s['weak_guesses'] ?? 0) + (int) ! $strong;
        $s['guessed'][$root.$name] = true;
        $s['queued_pages'][$host.$prefix.$name.'/?'] = true;
        $s['pages']['hot'][] = ["https://{$host}{$prefix}{$name}/", 2, max(1, self::score('/'.$name.'/')), $s['hot_seq'] = ($s['hot_seq'] ?? 0) + 1];
    }

    /**
     * Os sitemaps: os que o robots.txt do domínio aponta (ou o /sitemap.xml), com os índices, até 5 arquivos; os
     * endereços entram na fila de páginas depois dos links da home.
     */
    private function sitemaps(array &$s, string $domain, callable $inScope): void
    {
        if (! $this->robotsFor($s, $domain)) {
            return;
        }

        $queue = $s['robots'][$domain]['sitemaps'] ?: ["https://{$domain}/sitemap.xml"];
        $read = 0;

        while ($queue !== [] && $read < self::MAX_SITEMAPS && $s['sitemap_urls'] < self::MAX_SITEMAP_URLS) {
            $url = array_shift($queue);

            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

            if (! $inScope($host) || str_ends_with(strtolower($path), '.gz') || ! $this->robotsFor($s, $host)) {
                continue;
            }

            $read++;
            $response = $this->fetch($s, $url);

            if (! $response['ok'] || ! preg_match_all('~<loc>\s*([^<\s]{1,2000})\s*</loc>~i', $response['body'], $m)) {
                continue;
            }

            $index = stripos($response['body'], '<sitemapindex') !== false;

            foreach ($m[1] as $loc) {
                $loc = html_entity_decode($loc, ENT_QUOTES | ENT_XML1);

                if ($index) {
                    $queue[] = $loc;
                } elseif ($s['sitemap_urls'] < self::MAX_SITEMAP_URLS && preg_match('~^https?://~i', $loc)) {
                    $s['sitemap_urls']++;
                    $this->addPage($s, 'sitemap', $loc, 1);
                }
            }
        }
    }

    /** O robots.txt do nome, lido uma vez (e só os primeiros nomes): falso, o nome passou do teto. */
    private function robotsFor(array &$s, string $host): bool
    {
        if (isset($s['robots'][$host])) {
            return true;
        }

        if (count($s['robots']) >= self::MAX_HOSTS) {
            return false;
        }

        $response = $this->fetch($s, "https://{$host}/robots.txt", wait: $s['requests'] > 0);
        $s['robots'][$host] = ['sitemaps' => $response['ok'] ? self::sitemapsIn($response['body']) : []];

        // O que o robots.txt pede para os buscadores não abrirem é pista: costuma ser a área escondida (o login, o painel).
        if ($response['ok']) {
            preg_match_all('~^\s*(?:dis)?allow\s*:\s*(/[^\s#*$]*)~im', substr($response['body'], 0, 512 * 1024), $m);

            foreach (array_unique($m[1]) as $path) {
                if ($path !== '/' && $s['robots_paths'] < self::MAX_ROBOTS_PATHS) {
                    $s['robots_paths']++;
                    $this->addPage($s, 'robots', "https://{$host}{$path}", 1);
                }
            }
        }

        return true;
    }

    /** @return array{ok: bool, status: ?int, url: string, headers: array<string, list<string>>, body: string} */
    private function fetch(array &$s, string $url, bool $wait = true): array
    {
        if ($wait) {
            Sleep::for(self::DELAY_MS)->milliseconds();
        }

        $s['requests']++;
        $asked = hrtime(true);
        $response = $this->fetcher->get($url, static function (): void {}, accept: 'text/html,application/javascript,application/json;q=0.9,*/*;q=0.5',
            maxBytes: self::MAX_BYTES, timeout: 10);
        // Para o resumo: o tempo que foi de rede e as requisições sem resposta (a varredura lenta é o site ou é o Security Monitor?).
        $s['net'] = ($s['net'] ?? 0) + (hrtime(true) - $asked) / 1e9;
        $s['unanswered'] = ($s['unanswered'] ?? 0) + (int) ($response['status'] === null);
        $blocked = in_array($response['status'], [403, 429], true);
        $s['blocks'] = $blocked ? $s['blocks'] + 1 : 0;
        $s['last_blocked'] = $blocked ? $url : $s['last_blocked'];

        // Por seção também: a que só recusa (6 vezes, sem nenhuma resposta boa) fica de fora, mesmo com as páginas e os
        // scripts de outras seções no meio (o global-files.… que nega os scripts dele).
        $section = self::section($url);

        if ($response['ok']) {
            $s['section_ok'][$section] = true;
        } elseif ($blocked && ! isset($s['section_ok'][$section]) && ($s['section_blocks'][$section] = ($s['section_blocks'][$section] ?? 0) + 1) >= self::MAX_BLOCKS) {
            $s['barred'][$section] = true;
        }

        return $response;
    }

    /** As linhas `Sitemap:` do robots.txt. @return list<string> */
    private static function sitemapsIn(string $body): array
    {
        preg_match_all('~^\s*sitemap\s*:\s*(\S{1,2000})~im', substr($body, 0, 512 * 1024), $m);

        return array_slice(array_values(array_unique($m[1])), 0, 20);
    }

    /**
     * O formato do endereço: o nome e os trechos, com o id igualado (o trecho com número, ou longo) e o trecho com hífen
     * pela 1ª palavra. /perfil/123 e /perfil/456 são o mesmo; /acompanhantes-sp e /acompanhantes-rj também; /104/ana e
     * /109/bia-costa também; /redefine/sms e /redefine/mail, não.
     */
    private static function shape(string $host, string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), fn (string $one) => $one !== ''));
        $id = fn (string $one) => preg_match('~\d~', $one) === 1 || strlen($one) > 40;

        // O que vem logo depois do id que abre o caminho é o título do item (/104/patricia, /109/bruna-costa): um formato só.
        if (count($segments) === 2 && $id($segments[0])) {
            return $host.'/:n/*';
        }

        return $host.'/'.implode('/', array_map(fn (string $one) => match (true) {
            $id($one) => ':n',
            str_contains($one, '-') => strtolower(strstr($one, '-', true)).'-*',
            default => strtolower($one),
        }, $segments));
    }

    /** O caminho citado num script que é de API (ou de coleta, login, socket): não é página. */
    private static function isApi(string $path): bool
    {
        // Pelo trecho inteiro do caminho: /api/… e /v1/… são API; /app/facial-auth/ não é.
        foreach (explode('/', strtolower($path)) as $word) {
            if (in_array($word, self::API_WORDS, true) || preg_match('~^v\d+$~', $word)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, array<string, array<string, mixed>>>  $found */
    private function collect(array &$found, string $host, string $url, string $body): void
    {
        $path = PentestMasker::pathWithoutValues((string) (parse_url($url, PHP_URL_PATH) ?: '/'), 199);
        // A URL que a tela mostra: o esquema, o nome e o caminho, com os nomes da query sem os valores.
        $shown = PentestMasker::pathWithoutValues($url, 300);

        foreach ($this->detector->findWithSpots($body) as $one) {
            $spots = array_map(fn (array $spot) => ['url' => $shown, 'path' => $path] + $spot, $one['spots']);

            if (isset($found[$host][$one['fp']])) {
                $key = &$found[$host][$one['fp']];
                $key['count'] = min($key['count'] + $one['count'], KeyDetector::MAX_COUNT);
                $key['spots'] = array_slice([...$key['spots'], ...$spots], 0, self::MAX_SPOTS);
                // O nome mais descritivo fica (o `s` de uma página perde para o STRIPE_KEY de um script).
                if ($one['name'] !== null && strlen((string) $key['name']) < 3 && strlen($one['name']) > strlen((string) $key['name'])) {
                    $key['name'] = $one['name'];
                }
                unset($key);
            } elseif (count($found[$host] ?? []) < KeyDetector::MAX_KEYS) {
                $found[$host][$one['fp']] = ['kind' => $one['kind'], 'masked' => $one['masked'], 'name' => $one['name'],
                    'path' => $path, 'fp' => $one['fp'], 'count' => $one['count'], 'spots' => array_slice($spots, 0, self::MAX_SPOTS)];
            }
        }
    }

    /** O caminho que a varredura nunca pede: binário, arquivo de dados ou link de ação. */
    private static function skipped(string $path, string $query): bool
    {
        $extension = self::extension($path);

        if (in_array($extension, self::BINARY, true) || in_array($extension, self::DATA, true)) {
            return true;
        }

        $text = strtolower(urldecode(urldecode($path.' '.$query)));

        foreach (self::DENIED_PARTS as $part) {
            if (str_contains($text, $part)) {
                return true;
            }
        }

        foreach (preg_split('/[^a-z0-9]+/', $text) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            if (in_array($word, self::DENIED, true)) {
                return true;
            }

            foreach (self::DENIED_STEMS as $stem) {
                if (str_starts_with($word, $stem) || str_ends_with($word, $stem)) {
                    return true;
                }
            }
        }

        foreach (explode('&', $query) as $pair) {
            if (in_array(strtolower(urldecode(explode('=', $pair, 2)[0])), self::DENIED_PARAMS, true)) {
                return true;
            }
        }

        return false;
    }

    private static function extension(string $path): string
    {
        $last = (string) substr($path, (int) strrpos($path, '/') + 1);

        return str_contains($last, '.') ? strtolower((string) substr($last, (int) strrpos($last, '.') + 1)) : '';
    }

    /** Os nomes da query, sem os valores e em ordem: o mesmo caminho com outros valores não se repete. */
    private static function queryNames(string $query): string
    {
        $names = array_unique(array_map(fn (string $pair) => explode('=', $pair, 2)[0], array_filter(explode('&', $query))));
        sort($names);

        return implode('&', $names);
    }

    /**
     * Os links (`<a href>`) e os scripts (`<script src>`, o `modulepreload`) da página.
     *
     * @return list<array{0: string, 1: bool}> [o endereço, se é script]
     */
    private static function links(string $html): array
    {
        $out = [];
        preg_match_all('/<(a|script|link|iframe)\b([^>]{0,2000})>/i', substr($html, 0, self::MAX_BYTES), $tags, PREG_SET_ORDER);

        foreach (array_slice($tags, 0, 5000) as [, $tag, $attributes]) {
            $tag = strtolower($tag);
            $attribute = in_array($tag, ['script', 'iframe'], true) ? 'src' : 'href';

            if (! preg_match('/\b'.$attribute.'\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $m)) {
                continue;
            }

            // Do <link>, só o script: o modulepreload e o preload `as="script"` (o preload de imagem ou de fonte não).
            if ($tag === 'link' && ! preg_match('/\brel\s*=\s*["\']?modulepreload/i', $attributes)
                && ! (preg_match('/\brel\s*=\s*["\']?preload/i', $attributes) && preg_match('/\bas\s*=\s*["\']?script/i', $attributes))) {
                continue;
            }

            $out[] = [html_entity_decode(($m[1] ?? '') !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '')), ENT_QUOTES | ENT_HTML5), in_array($tag, ['script', 'link'], true)];
        }

        return $out;
    }

    /** O endereço absoluto do link, sem o fragmento; nulo o que não é página (mailto:, javascript:…). */
    private static function absolute(string $base, string $ref): ?string
    {
        $ref = trim($ref);

        if ($ref === '' || $ref[0] === '#' || preg_match('/^(?!https?:)[a-z][a-z0-9+.-]*:/i', $ref)) {
            return null;
        }

        $ref = (string) preg_replace('/#.*$/s', '', $ref);
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        $url = match (true) {
            (bool) preg_match('#^https?://#i', $ref) => $ref,
            str_starts_with($ref, '//') => ($parts['scheme'] ?? 'https').':'.$ref,
            str_starts_with($ref, '/') => $origin.$ref,
            str_starts_with($ref, '?') => $origin.($parts['path'] ?? '/').$ref,
            default => $origin.(preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/') ?: '/').$ref,
        };

        // Os "." e ".." do caminho.
        $split = parse_url($url);

        if ($split === false || empty($split['host'])) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $split['path'] ?? '/') as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }

        $path = implode('/', $segments);

        return strtolower($split['scheme'] ?? 'https').'://'.strtolower($split['host']).(str_starts_with($path, '/') ? $path : '/'.$path)
            .(isset($split['query']) ? '?'.$split['query'] : '');
    }
}
