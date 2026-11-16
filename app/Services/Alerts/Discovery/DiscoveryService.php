<?php

namespace App\Services\Alerts\Discovery;

use App\Exceptions\ActionRefused;
use App\Jobs\DiscoverDomainStack;
use App\Models\DomainFinding;
use App\Models\MonitoredDomain;
use App\Services\Alerts\InterviewService;
use App\Support\DomainActivity;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Descoberta passiva das tecnologias de um domínio. Nada é consultado no
 * domínio sem aceite e posse comprovada (a posse é da conta: DomainOwnership).
 *
 * O que a descoberta reconhece entra direto no perfil (origem "discovery"),
 * sem o cliente precisar revisar. Ele pode remover depois; o que remove fica
 * marcado como `ignored` e não volta nas execuções seguintes.
 *
 * Carga no site do cliente: no máximo 1 varredura a cada 30 dias por domínio,
 * seja qual for o gatilho; cada uma com teto de requisições (config
 * `max_requests`) e sem repetir URL; só as páginas que dizem algo (inicial,
 * erro e o blog que a inicial linkar).
 */
class DiscoveryService
{
    /** Páginas barradas por desafio anti-robô na última observação; viram aviso ao cliente. */
    private array $walls = [];

    public function __construct(
        private DnsResolver $dns,
        private SafeFetcher $fetcher,
        private StackDetector $detector,
        private InterviewService $interview,
        private SignalExtractor $extractor,
        private AutoCatalog $autoCatalog,
    ) {}

    /**
     * Depois de liberar o robô no firewall, o cliente pede para reler só as
     * páginas que foram barradas, 1 vez por dia. Não é uma varredura nova
     * (essa é mensal): a fila roda retryWalls().
     */
    public function queueWallsRetry(MonitoredDomain $domain): void
    {
        if (! Settings::bool('alerts.enabled') || ! Settings::bool('alerts.discovery_enabled')) {
            throw new ActionRefused('A descoberta automática está desligada no momento.');
        }

        if ($at = $domain->wallsRetryAvailableAt()) {
            throw new ActionRefused('Já lemos de novo as páginas barradas hoje. A próxima tentativa fica liberada em '
                .$at->timezone('America/Sao_Paulo')->format('d/m \à\s H:i').'.');
        }

        // Marca já: dois cliques seguidos não viram duas leituras.
        $domain->forceFill(['walls_retry_at' => now()])->save();
        DiscoverDomainStack::dispatch($domain->id, retryWalls: true);
    }

    /** "Adicionar de volta": a tecnologia que o cliente removeu volta ao perfil, com origem "discovery". */
    public function acceptFinding(DomainFinding $finding): void
    {
        $profile = $finding->domain->profile;

        if (! $profile->techs()->where('tech_catalog.id', $finding->tech_id)->exists()) {
            $profile->techs()->attach($finding->tech_id, [
                'source' => 'discovery',
                'confidence' => $finding->confidence,
                'created_at' => now(),
            ]);
        }

        $finding->update(['status' => 'accepted']);
        $this->interview->recompile($profile->refresh());
    }

    /** "Remover": a tecnologia sai do perfil e as próximas descobertas não a devolvem. */
    public function ignoreFinding(DomainFinding $finding): void
    {
        $profile = $finding->domain->profile;

        // Só sai o que a descoberta pôs; o que veio da entrevista se tira na entrevista.
        $profile->techs()->wherePivot('source', 'discovery')->detach($finding->tech_id);
        $finding->update(['status' => 'ignored']);
        $this->interview->recompile($profile->refresh());
    }

    /** Roda a descoberta e grava achados, log e próxima execução. */
    /**
     * Varredura completa, no máximo 1 a cada 30 dias por domínio (o admin pode
     * espaçar mais, nunca menos), venha de onde vier: agendador, verificação,
     * admin ou fila. Só o operador, no terminal, força (`--force`).
     *
     * @return bool se rodou (ou reaproveitou a varredura de outro perfil)
     */
    public function run(MonitoredDomain $domain, bool $force = false): bool
    {
        if (! $domain->canDiscover() || (! $force && ! $domain->scanDue())) {
            return false;
        }

        // Nunca duas leituras do mesmo site ao mesmo tempo (fila, admin e agendador).
        return (bool) Cache::lock('alerts-discovery:'.$domain->domain, 300)->get(function () use ($domain, $force) {
            // Com um teste de segurança no site, a descoberta espera: a próxima rodada tenta de novo (T10). Conferido
            // já com a trava na mão (C8 do Red Team): a 1ª ação do teste confere a mesma trava, e um dos dois vê o outro.
            if (DomainActivity::pentestActive($domain->domain)) {
                Log::info('Descoberta adiada: teste de segurança em andamento', ['domain' => $domain->domain]);

                return false;
            }

            $domain->refresh();

            if (! $force && ! $domain->scanDue()) {
                return false;
            }

            if (! $force && ($sibling = $this->recentScanOfSameDomain($domain))) {
                $this->reuse($domain, $sibling);

                return true;
            }

            $this->scan($domain);

            return true;
        });
    }

    /**
     * Depois que o cliente libera o robô no firewall: relê só as páginas que
     * o desafio anti-robô barrou, sem refazer a varredura nem contar como uma.
     *
     * @return int quantas páginas barradas foram lidas agora
     */
    public function retryWalls(MonitoredDomain $domain): int
    {
        $walls = $domain->walls();

        if (! $domain->canDiscover() || $walls === []) {
            return 0;
        }

        return (int) Cache::lock('alerts-discovery:'.$domain->domain, 300)->get(function () use ($domain, $walls) {
            // Com um teste de segurança no site, espera; conferido já com a trava na mão (C8 do Red Team).
            if (DomainActivity::pentestActive($domain->domain)) {
                return 0;
            }

            $log = $domain->last_log ?? [];
            $add = function (string $message, string $level = 'info') use (&$log) {
                $log[] = ['t' => now()->toIso8601String(), 'msg' => $message, 'level' => $level];
            };

            $add('nova tentativa só das páginas barradas ('.count($walls).')');
            $this->walls = [];
            $this->fetcher->startScan(count($walls) * 2);
            $read = ['headers' => [], 'cookies' => [], 'body' => ''];
            $opened = 0;

            foreach ($walls as $wall) {
                $page = $this->fetcher->get($wall['url'], $add);
                $before = count($this->walls);
                $read = $this->merge($read, $page, 'releitura', $add);
                $opened += $page['status'] !== null && count($this->walls) === $before ? 1 : 0;

                if ($page['status'] === null && ! $page['blocked']) {
                    $this->walls[] = $wall;
                }
            }

            $seen = $this->extractor->seen($domain->domain, $read + ['url' => 'https://'.$domain->domain.'/'], [], [], [], []);
            $this->autoCatalog->register($seen, $domain->domain);
            $detected = $this->detector->withParents($this->detector->detect($seen));
            $added = $this->saveFindings($domain, $detected);
            $add(sprintf('%d de %d página(s) lida(s) agora; %d tecnologia(s) nova(s) no perfil · %d requisição(ões) ao site',
                $opened, count($walls), $added, $this->fetcher->spent()), 'ok');

            $domain->forceFill([
                'last_log' => $log,
                'hints' => array_merge(collect($domain->hints ?? [])->reject(fn ($h) => is_array($h))->values()->all(), $this->walls),
                'walls_retry_at' => now(),
            ])->save();

            return $opened;
        });
    }

    /** Mesmo domínio, outro perfil (outra pessoa da empresa), varrido há menos de 30 dias. */
    private function recentScanOfSameDomain(MonitoredDomain $domain): ?MonitoredDomain
    {
        return MonitoredDomain::query()
            ->ofAlerts()
            ->where('domain', $domain->domain)
            ->whereKeyNot($domain->id)
            ->whereIn('discovery_status', ['done', 'partial'])
            ->where('last_scan_at', '>', now()->subDays(MonitoredDomain::scanIntervalDays()))
            ->latest('last_scan_at')
            ->first();
    }

    /** Usa o resultado da varredura do outro perfil: nenhuma requisição ao site. */
    private function reuse(MonitoredDomain $domain, MonitoredDomain $sibling): void
    {
        $detected = $sibling->findings()->with('tech')->get()
            ->filter(fn (DomainFinding $f) => $f->tech !== null)
            ->map(fn (DomainFinding $f) => [
                'tech' => $f->tech, 'label' => $f->label, 'version' => $f->version,
                'evidence' => $f->evidence, 'confidence' => $f->confidence, 'category' => $f->category,
            ])->values()->all();
        $added = $this->saveFindings($domain, $detected);
        $at = fn ($time) => $time->timezone('America/Sao_Paulo')->format('d/m H:i');

        $domain->forceFill([
            'discovery_status' => $sibling->discovery_status,
            'last_error' => $sibling->last_error,
            'last_run_at' => now(),
            'last_scan_at' => $sibling->last_scan_at,
            'next_run_at' => $sibling->next_run_at,
            'walls_retry_at' => null,
            'hints' => $sibling->hints,
            'last_log' => [
                ['t' => now()->toIso8601String(), 'msg' => 'posse: domínio comprovado em '.$at($domain->verified_at), 'level' => 'ok'],
                ['t' => now()->toIso8601String(), 'msg' => "o site foi varrido em {$at($sibling->last_scan_at)} para outro perfil com o mesmo domínio: resultado reaproveitado, nenhuma requisição ao site", 'level' => 'info'],
                ['t' => now()->toIso8601String(), 'msg' => count($detected).' tecnologia(s) reaproveitada(s), '.$added.' adicionada(s) ao perfil', 'level' => 'ok'],
            ],
        ])->save();
    }

    private function scan(MonitoredDomain $domain): void
    {
        $log = [];
        $add = function (string $message, string $level = 'info') use (&$log) {
            $log[] = ['t' => now()->toIso8601String(), 'msg' => $message, 'level' => $level];
        };

        $domain->forceFill(['discovery_status' => 'running'])->save();
        $add('posse: domínio comprovado em '.$domain->verified_at->timezone('America/Sao_Paulo')->format('d/m H:i'), 'ok');

        if (! $domain->allowsMorePages()) {
            $add("termo {$domain->consent_version}: só a página inicial (o termo atual também lê a página de erro e o blog)");
        }

        $page = null;
        $seen = $this->observe($domain->domain, $add, $page, $domain->allowsMorePages());

        // Sinal que nenhuma regra conhece vira item do catálogo, monitorado.
        $created = $this->autoCatalog->register($seen, $domain->domain);

        if ($created->isNotEmpty()) {
            $add($created->count().' item(ns) fora do catálogo cadastrado(s) para monitoria: '.$created->pluck('name')->implode(', '), 'ok');
        }

        $detected = $this->detector->withParents($this->detector->detect($seen));
        $added = $this->saveFindings($domain, $detected);
        $add(count($detected).' tecnologia(s) reconhecida(s), '.$added.' adicionada(s) ao perfil', 'ok');

        $status = match (true) {
            $page['blocked'] => 'blocked',
            ! $page['ok'] && $detected === [] => 'failed',
            ! $page['ok'] => 'partial',
            default => 'done',
        };

        // Varredura que não chegou a pedir nada ao site (DNS sem resposta, IP
        // bloqueado) não gasta o mês: tenta de novo amanhã.
        $hit = $this->fetcher->spent() > 0;

        $domain->forceFill([
            'discovery_status' => $status,
            'last_error' => $page['ok'] ? null : $page['error'],
            'last_run_at' => now(),
            'last_scan_at' => $hit ? now() : $domain->last_scan_at,
            'next_run_at' => $hit ? now()->addDays(MonitoredDomain::scanIntervalDays()) : now()->addDay(),
            'walls_retry_at' => null,
            'last_log' => $log,
            'hints' => array_merge($this->detector->hints($seen), $this->walls),
        ])->save();
    }

    /** Diagnóstico de operador (CLI): mesmas travas, nada é gravado. */
    public function probe(string $host): array
    {
        $log = [];
        $add = function (string $message, string $level = 'info') use (&$log) {
            $log[] = ['t' => now()->toIso8601String(), 'msg' => $message, 'level' => $level];
        };

        $page = null;
        $seen = $this->observe($host, $add, $page, morePages: true);

        return [
            'log' => $log,
            'findings' => $this->detector->withParents($this->detector->detect($seen)),
            'new' => $this->autoCatalog->candidates($seen, $host),
            'hints' => array_merge($this->detector->hints($seen), $this->walls),
        ];
    }

    /**
     * DNS (MX, TXT, NS, CNAME do www), a página inicial inteira e, se o termo
     * permitir, a página de erro e o blog, já convertidos nos sinais que o
     * detector e o cadastro automático usam.
     */
    private function observe(string $host, callable $add, ?array &$page, bool $morePages = false): array
    {
        $this->walls = [];
        $this->fetcher->startScan((int) config('secmon.alerts.discovery.max_requests', 5));

        $mx = $this->dns->mx($host);
        $txt = $this->dns->txt($host);
        $ns = $this->dns->ns($host);
        $cname = $this->dns->cname('www.'.$host);
        $spf = array_values(array_filter($txt, fn ($t) => str_starts_with(strtolower(trim($t)), 'v=spf1')));
        $other = array_values(array_filter($txt, fn ($t) => ! str_starts_with(strtolower(trim($t)), 'v=spf1')));

        $add('DNS MX → '.($mx ? implode(', ', $mx) : 'nenhum'));
        $add('DNS SPF → '.($spf ? implode(' | ', $spf) : 'nenhum'));
        $add('DNS TXT → '.($other ? count($other).' registro(s): '.implode(' | ', array_map(fn ($t) => mb_strimwidth($t, 0, 60, '…'), $other)) : 'nenhum'));
        $add('DNS NS → '.($ns ? implode(', ', $ns) : 'nenhum'));
        $add('DNS CNAME www → '.($cname ? implode(', ', $cname) : 'nenhum'));

        $page = $this->fetcher->get('https://'.$host.'/', $add);

        // Sem HTTPS respondendo, uma tentativa em HTTP (bloqueio nunca é refeito).
        if ($page['unreachable']) {
            $page = $this->fetcher->get('http://'.$host.'/', $add);
        }

        if ($this->isChallenge($page)) {
            $this->wall('página inicial', $page, $add);
        }

        $read = $page;

        if ($morePages && ! $page['blocked'] && ! $page['unreachable']) {
            $read = $this->withErrorPage($read, $page, $add);
            $read = $this->withBlog($read, $page, $add);
        }

        $seen = $this->extractor->seen($host, $read, $mx, $txt, $ns, $cname);

        $add(sprintf(
            'página: %d meta generator, %d script(s) de terceiros, %d plugin(s)/tema(s) WordPress',
            count($seen['generators']), count($seen['scripts']), count($seen['wordpress']),
        ));
        $add(sprintf('%d requisição(ões) ao site nesta varredura (teto %d)', $this->fetcher->spent(), $this->fetcher->budget()));

        return $seen;
    }

    /**
     * Um GET num endereço que não existe. A página inicial costuma sair do
     * cache da CDN ou de um front separado; o 404 é respondido pelo servidor
     * de aplicação, com os cookies e cabeçalhos dele (o Laravel por trás de
     * um front Nuxt, por exemplo). Os sinais dela somam aos da página inicial.
     */
    private function withErrorPage(array $read, array $home, callable $add): array
    {
        // 1 requisição: redirect não é seguido (os cookies e cabeçalhos do 3xx já vêm do servidor de aplicação).
        $error = $this->fetcher->get($this->origin($home['url']).'/secmon-check-'.bin2hex(random_bytes(5)), $add, expectMissing: true, followRedirects: false);

        return $this->merge($read, $error, 'página de erro', $add);
    }

    /**
     * O blog costuma ser outro sistema (um WordPress em /blog/ ao lado de um
     * app Laravel). Só é lido se a página inicial linkar para ele no próprio
     * domínio: nada de tentar endereço às cegas.
     */
    private function withBlog(array $read, array $home, callable $add): array
    {
        $url = $this->extractor->blogUrl($home['body'], $home['url']);

        if (! $url) {
            $add('blog: a página inicial não linka um blog; nada a ler');

            return $read;
        }

        $add("blog: link na página inicial → {$url}");
        $blog = $this->fetcher->get($url, $add, expectMissing: true);

        if (! $blog['blocked'] && in_array($blog['status'], [404, 410], true)) {
            $add('blog: não há');

            return $read;
        }

        if ($blog['status'] !== null && $blog['status'] >= 400 && ! $this->isChallenge($blog)) {
            $add("blog: HTTP {$blog['status']}, não lido", 'error');

            return $read;
        }

        return $this->merge($read, $blog, 'blog', $add);
    }

    /** Soma os sinais de outra página aos já lidos e diz no log o que ela trouxe de novo. */
    private function merge(array $read, array $extra, string $label, callable $add): array
    {
        if ($extra['blocked'] || $extra['status'] === null) {
            return $read;
        }

        if ($this->isChallenge($extra)) {
            $this->wall($label, $extra, $add);

            return $read;
        }

        $generators = $this->extractor->generators($extra['body']);
        $wordpress = count($this->extractor->wordpressItems($extra['body']));
        $cookies = array_values(array_diff($extra['cookies'], $read['cookies']));
        $headers = array_values(array_diff(array_keys($extra['headers']), array_keys($read['headers']), ['set-cookie']));
        $news = array_filter([
            $generators ? 'meta generator '.implode(', ', $generators) : null,
            $wordpress ? $wordpress.' plugin(s)/tema(s) WordPress' : null,
            $cookies ? 'cookies '.implode(', ', $cookies) : null,
            $headers ? 'cabeçalhos '.implode(', ', $headers) : null,
        ]);
        $add($label.': '.($news ? implode('; ', $news) : 'nada além do que a página inicial mostrou'));

        foreach ($extra['headers'] as $name => $values) {
            $read['headers'][$name] = array_values(array_unique(array_merge($read['headers'][$name] ?? [], $values)));
        }

        $read['cookies'] = array_values(array_unique(array_merge($read['cookies'], $extra['cookies'])));
        $read['body'] .= "\n".$extra['body'];

        return $read;
    }

    /** Desafio anti-robô (Cloudflare "Just a moment...", página de bloqueio de WAF): não é a página do site. */
    private function isChallenge(array $page): bool
    {
        if (str_contains(strtolower(implode(' ', $page['headers']['cf-mitigated'] ?? [])), 'challenge')) {
            return true;
        }

        return in_array($page['status'], [403, 429, 503], true)
            && preg_match('/<title>\s*(Just a moment\.\.\.|Attention Required! \| Cloudflare|Access Denied|Sucuri WebSite Firewall)/i', substr($page['body'], 0, 5000)) === 1;
    }

    /**
     * O Security Monitor não tenta passar por desafio anti-robô: avisa o dono do site,
     * que pode liberar o robô numa regra do firewall dele.
     */
    private function wall(string $label, array $page, callable $add): void
    {
        $cloudflare = in_array('cloudflare', array_map('strtolower', $page['headers']['server'] ?? []), true);
        [$by, $byWho] = $cloudflare
            ? ['o desafio anti-robô da Cloudflare', 'pelo desafio anti-robô da Cloudflare']
            : ['a proteção anti-robô do site', 'pela proteção anti-robô do site'];
        $how = $cloudflare ? ' (no Cloudflare: Security → WAF → Custom rules, ação Skip)' : '';

        $add("{$label}: barrada {$byWho} em {$page['url']}", 'error');
        $this->walls[] = [
            'tipo' => 'bloqueio',
            'url' => $page['url'],
            'por' => $cloudflare ? 'cloudflare' : 'waf',
            'texto' => "não conseguimos ler {$page['url']} porque {$by} barrou o acesso. Para incluir essa página na descoberta, "
                .'libere o User-Agent com "SecurityMonitorBot" numa regra do firewall'.$how.'.',
        ];
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'];
    }

    /**
     * Grava os achados e coloca no perfil o que ainda não está lá. O que o
     * cliente removeu (`ignored`) continua registrado, mas não volta.
     *
     * @return int quantas tecnologias entraram no perfil agora
     */
    private function saveFindings(MonitoredDomain $domain, array $detected): int
    {
        $existing = $domain->findings()->get()->keyBy(fn (DomainFinding $f) => $f->tech_id ?: $f->label);
        $profile = $domain->profile;
        $inProfile = $profile->techs()->pluck('tech_catalog.id')->all();
        $added = 0;

        foreach ($detected as $item) {
            $techId = $item['tech']->id;
            $finding = $existing->get($techId) ?? new DomainFinding(['domain_id' => $domain->id, 'tech_id' => $techId, 'found_at' => now()]);

            if ($finding->status !== 'ignored') {
                if (! in_array($techId, $inProfile, true)) {
                    DB::table('alert_profile_techs')->insert([
                        'profile_id' => $profile->id,
                        'tech_id' => $techId,
                        'source' => 'discovery',
                        'confidence' => $item['confidence'],
                        'created_at' => now(),
                    ]);
                    $inProfile[] = $techId;
                    $added++;
                }

                $finding->status = 'accepted';
            }

            $finding->forceFill([
                'label' => $item['label'],
                'category' => $item['category'],
                'version' => $item['version'],
                'evidence' => mb_substr($item['evidence'], 0, 500),
                'confidence' => $item['confidence'],
                'last_seen_at' => now(),
            ])->save();
        }

        if ($added > 0) {
            $this->interview->recompile($profile->refresh());
        }

        return $added;
    }
}
