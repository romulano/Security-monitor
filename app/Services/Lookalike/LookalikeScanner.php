<?php

namespace App\Services\Lookalike;

use App\Jobs\CheckLookalikeCertificates;
use App\Jobs\CheckLookalikeNames;
use App\Jobs\FinishLookalikeScan;
use App\Models\LookalikeDomain;
use App\Models\LookalikeWatch;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\Discovery\IpGuard;
use App\Support\DomainActivity;
use App\Support\RegistrableDomain;
use Illuminate\Bus\Batch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Throwable;

/**
 * Varredura do Radar de domínios de um domínio verificado:
 *
 * 1. gera as variações do nome (e busca certificados com a marca);
 * 2. confere no DNS quais existem (só DNS: nenhuma página é aberta aqui);
 * 3. dos que existem, lê o registro (RDAP) e a hospedagem;
 * 4. pede ao navegador a visita dos que têm IP público e mudaram ou ficaram
 *    sem print recente;
 *
 * A completa roda todo dia às `scan_at` (01:00 de Brasília). Os passos 1 e 2 vão em lotes de
 * jobs na fila `lookalikes` (`scanInBatches`: cada lote tem o seu retry e um
 * que falha não perde os outros); o último job junta os lotes e faz 3 e 4
 * (`complete`). `scan` faz tudo na hora, sem fila (CLI --sync, testes).
 * `recheck` só relê o DNS dos que já existem, que é onde aparece o golpe
 * "acordando"; o agendador não o chama, porque a completa diária já faz isso.
 */
class LookalikeScanner
{
    private array $log = [];

    public function __construct(
        private DnsResolver $dns,
        private VariantGenerator $variants,
        private CertificateSearch $certificates,
        private NameExistence $names,
        private RdapClient $rdap,
        private BrowserQueue $browser,
        private RiskScorer $scorer,
    ) {}

    /** Liga (ou religa) um Radar já integrado. A próxima varredura fica para já. */
    public function enable(LookalikeWatch $watch): LookalikeWatch
    {
        $watch->forceFill(['status' => 'active', 'next_scan_at' => now()])->save();

        return $watch;
    }

    /** Varredura completa na hora, sem fila. */
    public function scan(LookalikeWatch $watch): void
    {
        if (! $plan = $this->prepare($watch)) {
            return;
        }

        try {
            foreach ($plan['chunks'] as $index => $candidates) {
                $this->checkNames($plan['run'], $index, $candidates, final: true);
            }

            $this->checkCertificates($plan['run'], $plan['label'], $plan['own'], final: true);
        } catch (Throwable $e) {
            report($e);
            $this->fail($watch, $plan['run'], 'Erro na varredura: '.mb_substr($e->getMessage(), 0, 300));

            return;
        }

        $this->complete($watch, $plan['run']);
    }

    /** Varredura completa em lotes de jobs; o último deles chama `complete`. */
    public function scanInBatches(LookalikeWatch $watch): void
    {
        if (! $plan = $this->prepare($watch)) {
            return;
        }

        try {
            $jobs = collect($plan['chunks'])
                ->map(fn (array $candidates, int $index) => new CheckLookalikeNames($watch->id, $plan['run'], $index, $candidates))
                ->push(new CheckLookalikeCertificates($watch->id, $plan['run'], $plan['label'], $plan['own']))
                ->all();

            $watchId = $watch->id;
            $runId = $plan['run'];

            Bus::batch($jobs)
                ->name("radar-{$watchId}")
                ->onQueue('lookalikes')
                ->allowFailures()
                ->finally(static fn (Batch $batch) => FinishLookalikeScan::dispatch($watchId, $runId))
                ->dispatch();
        } catch (Throwable $e) {
            report($e);
            $this->fail($watch, $plan['run'], 'Erro ao enfileirar a varredura: '.mb_substr($e->getMessage(), 0, 300));
        }
    }

    public function recheck(LookalikeWatch $watch): void
    {
        $this->log = [];
        $watch->loadMissing('domain');

        if (! $watch->domain?->isVerified()) {
            $this->finish($watch, 'failed', 'O domínio deixou de estar verificado.', false);

            return;
        }

        // Os lotes de uma varredura completa ainda andam: a releitura espera a próxima rodada.
        if (ScanRun::running($watch->id)) {
            return;
        }

        $watch->update(['scan_status' => 'running']);

        try {
            $brand = BrandContext::for($watch, $this->dns);
            $this->note("DNS de {$brand->domain}: ".(count($brand->ns) ? implode(', ', $brand->ns) : 'sem NS').'; o site do cliente não é visitado.');
            $this->probeAndQueue($watch, $brand, [], false);
        } catch (Throwable $e) {
            report($e);
            $this->finish($watch, 'failed', 'Erro na varredura: '.mb_substr($e->getMessage(), 0, 300), false);
        }
    }

    /**
     * Passo 1: a trava, as variações e os lotes de nomes a conferir. Os já
     * conhecidos ficam de fora (são relidos por inteiro no probe()).
     *
     * @return array{run: string, label: string, own: list<string>, chunks: list<list<array{0: string, 1: ?string}>>}|null
     */
    private function prepare(LookalikeWatch $watch): ?array
    {
        $this->log = [];
        $watch->loadMissing('domain');

        if (! $watch->domain?->isVerified()) {
            $this->finish($watch, 'failed', 'O domínio deixou de estar verificado.', true);

            return null;
        }

        if (! $runId = ScanRun::begin($watch->id)) {
            return null;
        }

        $watch->update(['scan_status' => 'running']);

        try {
            $brand = BrandContext::for($watch, $this->dns);
            $this->note("DNS de {$brand->domain}: ".(count($brand->ns) ? implode(', ', $brand->ns) : 'sem NS').'; o site do cliente não é visitado.');

            $variants = $this->variants->generate($brand->domain, array_merge($watch->brandNames(), [$watch->company_name]), $brand->ownDomains);
            $this->note(count($variants).' variações geradas para '.$brand->domain.'.');

            $known = $watch->lookalikes()->pluck('host')->flip();
            $candidates = [];

            foreach ($variants as $host => $technique) {
                if (! $known->has($host)) {
                    $candidates[] = [$host, $technique];
                }
            }

            $chunks = array_chunk($candidates, max(1, (int) config('secmon.lookalikes.dns_batch_size', 100)));

            ScanRun::put($runId, 'meta', [
                'started_at' => now()->toIso8601String(),
                'variants' => array_keys($variants),
                'chunks' => count($chunks),
                'log' => $this->log,
            ]);

            return ['run' => $runId, 'label' => VariantGenerator::split($brand->domain)[0], 'own' => $brand->ownDomains, 'chunks' => $chunks];
        } catch (Throwable $e) {
            report($e);
            $this->fail($watch, $runId, 'Erro na varredura: '.mb_substr($e->getMessage(), 0, 300));

            return null;
        }
    }

    /**
     * Um lote de nomes no DNS, guardado para o último job. Em job (`final`
     * falso), lote com DNS mudo demais falha para tentar de novo mais tarde;
     * na última tentativa guarda o que conseguiu e o resto fica para a próxima
     * varredura.
     *
     * @param  list<array{0: string, 1: ?string}>  $candidates
     */
    public function checkNames(string $runId, int $index, array $candidates, bool $final): void
    {
        $result = $this->names->checkCandidates($candidates);

        if (! $final && $result['unanswered'] > $result['checked'] * (float) config('secmon.lookalikes.dns_unanswered_max', 0.2)) {
            throw new RuntimeException("DNS sem resposta para {$result['unanswered']} de {$result['checked']} nomes do lote {$index}.");
        }

        ScanRun::put($runId, "chunk.{$index}", $result);
    }

    /** Certificados com a marca e quais desses nomes existem, guardados para o último job. */
    public function checkCertificates(string $runId, string $label, array $ownDomains, bool $final): void
    {
        $certs = $this->certificates->search($label, $ownDomains);

        if (! $final && $certs['error'] && $certs['retry']) {
            throw new RuntimeException($certs['error']);
        }

        ScanRun::put($runId, 'certs', [
            'hosts' => $certs['hosts'],
            'found' => $certs['hosts'] ? $this->names->check($certs['hosts'])['found'] : [],
            'error' => $certs['error'],
        ]);
    }

    /**
     * Passos 3 e 4, com o que os lotes deixaram: junta os resultados, lê o
     * registro e a hospedagem dos que existem e pede as páginas ao navegador.
     */
    public function complete(LookalikeWatch $watch, string $runId): void
    {
        $meta = ScanRun::get($runId, 'meta');
        $this->log = $meta['log'] ?? [];
        $watch->loadMissing('domain');

        try {
            if (! $meta) {
                $this->finish($watch, 'failed', 'A varredura perdeu o estado no meio do caminho e recomeça na próxima rodada.', true);
            } elseif (! $watch->domain?->isVerified()) {
                $this->finish($watch, 'failed', 'O domínio deixou de estar verificado.', true);
            } else {
                $brand = BrandContext::for($watch, $this->dns);
                $found = $this->collect($watch, $runId, $meta);
                $this->probeAndQueue($watch, $brand, $found, true);
            }
        } catch (Throwable $e) {
            report($e);
            $this->finish($watch, 'failed', 'Erro na varredura: '.mb_substr($e->getMessage(), 0, 300), true);
        } finally {
            ScanRun::end($watch->id, $runId, (int) ($meta['chunks'] ?? 0));
        }
    }

    /**
     * Os lotes e os certificados juntos: quem existe, os livres para registro
     * defensivo e as contagens do que o cliente vê.
     *
     * @return array<string, array{techniques: list<string>, certificate: bool}>
     */
    private function collect(LookalikeWatch $watch, string $runId, array $meta): array
    {
        $found = [];
        $free = [];
        $unanswered = 0;
        $lost = 0;

        for ($index = 0; $index < $meta['chunks']; $index++) {
            if (! ($chunk = ScanRun::get($runId, "chunk.{$index}")) || isset($chunk['failed'])) {
                $lost++;

                continue;
            }

            foreach ($chunk['found'] as $host => $technique) {
                $found[$host] = ['techniques' => array_filter([$technique]), 'certificate' => false, 'seed' => $meta['seeds'][$host] ?? null];
            }

            array_push($free, ...$chunk['free']);
            $unanswered += $chunk['unanswered'];
        }

        $certs = ScanRun::get($runId, 'certs') ?? ['hosts' => [], 'found' => [], 'error' => 'a busca em certificados não terminou'];
        $this->note($certs['error']
            ? 'Certificados: '.$certs['error'].'. A busca volta na próxima varredura.'
            : count($certs['hosts']).' '.(count($certs['hosts']) === 1 ? 'nome achado' : 'nomes achados').' em certificados HTTPS com "'.VariantGenerator::split($watch->domain->domain)[0].'".');

        foreach ($certs['found'] as $host) {
            $found[$host] ??= ['techniques' => [], 'certificate' => false];
            $found[$host]['certificate'] = true;
        }

        $new = count(array_diff_key($found, $watch->lookalikes()->pluck('host')->flip()->all()));
        $existing = $watch->lookalikes()->count();
        $elapsed = Carbon::parse($meta['started_at'])->diffInSeconds(now(), true);
        $this->note(count($meta['variants']).' variações em '.$meta['chunks'].' '.($meta['chunks'] === 1 ? 'lote' : 'lotes').' conferidas no DNS em '.$this->duration((int) $elapsed).'.');

        if ($unanswered || $lost) {
            $this->note(($lost ? "{$lost} ".($lost === 1 ? 'lote falhou' : 'lotes falharam').' e ' : '').($unanswered ? "{$unanswered} nomes ficaram sem resposta do DNS; " : 'seus nomes ficaram sem conferir; ').'voltam na próxima varredura.');
        }

        $this->note(($new + $existing).' existem no DNS'.($new ? " ({$new} novos desde a última varredura)" : '').'.');

        $certHosts = array_flip($certs['hosts']);
        $watch->free_variants = array_slice(array_values(array_filter($free, fn (array $f) => ! isset($certHosts[$f['host']]))), 0, (int) config('secmon.lookalikes.free_variants', 15));
        $watch->stats = array_merge($watch->stats ?? [], [
            'variants' => count($meta['variants']),
            'certificates' => count($certs['hosts']),
            'certificates_error' => $certs['error'],
            'checked' => count($meta['variants']) + count(array_diff($certs['hosts'], $meta['variants'])),
            'unanswered' => $unanswered,
        ]);

        return $found;
    }

    /**
     * Relê o DNS dos conhecidos e dos achados, lê registro e hospedagem, pede
     * as visitas ao navegador e grava o resultado da varredura.
     *
     * @param  array<string, array{techniques: list<string>, certificate: bool}>  $found
     */
    private function probeAndQueue(LookalikeWatch $watch, BrandContext $brand, array $found, bool $full): void
    {
        $lookalikes = $watch->lookalikes()->with('lastCapture')->get();

        // Os que apareceram nesta varredura e ainda não estavam na lista.
        foreach (array_diff_key($found, $lookalikes->keyBy('host')->all()) as $host => $meta) {
            $lookalikes->push($watch->lookalikes()->make([
                'host' => $host,
                'techniques' => $meta['techniques'],
                'from_certificate' => $meta['certificate'],
                'first_seen_at' => now(),
                // A semente de subdomínio (E4b) entra nos sinais e o RiskScorer a mantém: "Imita login.acme.com.br".
                'signals' => empty($meta['seed']) ? null : [RiskScorer::subdomainSignal($meta['seed'])],
            ]));
        }

        $toCapture = $this->probe($lookalikes, $brand);
        // A referência vai antes dos domínios na fila: o navegador já compara com ela nesta mesma subida.
        $this->reference($watch, $brand);

        $queued = $toCapture->isEmpty() ? 0 : $this->browser->enqueue($watch, $toCapture, $brand->ownDomains);
        $this->note($queued ? "{$queued} ".($queued === 1 ? 'página pedida' : 'páginas pedidas').' ao navegador.' : 'Nenhuma página precisava de visita nova.');

        $watch->stats = array_merge($watch->stats ?? [], [
            'registered' => $watch->lookalikes()->where('state', '!=', 'gone')->count(),
            'queued' => $queued,
            'rechecked_at' => now()->toIso8601String(),
        ]);
        $this->finish($watch, 'done', null, $full);
    }

    private function fail(LookalikeWatch $watch, string $runId, string $error): void
    {
        ScanRun::end($watch->id, $runId);
        $this->finish($watch, 'failed', $error, true);
    }

    private function duration(int $seconds): string
    {
        return $seconds >= 60 ? intdiv($seconds, 60).' min '.($seconds % 60).' s' : "{$seconds} s";
    }

    /**
     * Relê o DNS de cada um, o registro (RDAP) e a hospedagem dos que precisam
     * (em lote, em paralelo), recalcula o nível e grava. Devolve os que o
     * navegador precisa visitar.
     *
     * @param  Collection<int, LookalikeDomain>  $lookalikes
     * @return Collection<int, LookalikeDomain>
     */
    private function probe(Collection $lookalikes, BrandContext $brand): Collection
    {
        $previousIps = [];

        foreach ($lookalikes as $lookalike) {
            $host = $lookalike->host;
            $registrable = RegistrableDomain::of($host) === $host;
            $previousIps[$host] = $lookalike->ips ?? [];

            $answers = [$this->dns->ipsOrNull($host), $registrable ? $this->dns->ipsOrNull('www.'.$host) : []];
            $ips = array_values(array_unique(array_merge(...array_map(fn (?array $a) => $a ?? [], $answers))));
            sort($ips);
            $ns = $registrable ? $this->dns->nsOrNull($host) : [];

            // DNS que não respondeu (timeout, SERVFAIL, resolvedor sobrecarregado) é "não sei", não "sem IP"
            // nem "sumiu do DNS": o que já se sabia fica, e a releitura conta como não feita.
            $silent = $ns === null || ($ips === [] && in_array(null, $answers, true));

            if ($silent) {
                continue;
            }

            $lookalike->fill([
                'ns' => $ns,
                'ips' => $ips,
                'mx' => $this->dns->mx($host),
                'last_checked_at' => now(),
            ]);
        }

        $alive = $lookalikes->reject(fn (LookalikeDomain $d) => $this->isGone($d));

        // Registro: na primeira vez e depois 1 vez por mês. Sem resposta, tenta na próxima releitura.
        $needRdap = $alive->filter(fn (LookalikeDomain $d) => RegistrableDomain::of($d->host) === $d->host
            && (! $d->rdap_checked_at || $d->rdap_checked_at->lt(now()->subDays(30))));

        foreach ($this->rdap->domains($needRdap->pluck('host')->all()) as $host => $rdap) {
            if ($rdap === []) {
                continue;
            }

            $lookalike = $needRdap->firstWhere('host', $host);
            $lookalike->rdap = $rdap;
            $lookalike->registered_at = isset($rdap['registered_at']) ? Carbon::parse($rdap['registered_at']) : null;
            $lookalike->rdap_checked_at = now();
        }

        // Hospedagem: dona da faixa do primeiro IP público, quando ele muda.
        $firstPublic = fn (LookalikeDomain $d) => collect($d->ips ?? [])->first(fn ($ip) => IpGuard::blockReason($ip) === null);
        $needHosting = $alive->filter(fn (LookalikeDomain $d) => ($ip = $firstPublic($d)) && ($d->hosting['ip'] ?? null) !== $ip);
        $hosting = $this->rdap->ips($needHosting->map($firstPublic)->unique()->values()->all());

        $previousNetworks = [];

        foreach ($needHosting as $lookalike) {
            $ip = $firstPublic($lookalike);
            $previousNetworks[$lookalike->host] = $lookalike->hosting['network'] ?? null;
            $lookalike->hosting = ($hosting[$ip] ?? []) ?: ['ip' => $ip];
        }

        $toCapture = collect();

        foreach ($lookalikes as $lookalike) {
            if ($this->decide($lookalike, $previousIps[$lookalike->host], $previousNetworks[$lookalike->host] ?? null)) {
                $toCapture->push($lookalike);
            }

            $this->scorer->apply($lookalike, $brand);
            $lookalike->save();
        }

        return $toCapture;
    }

    /**
     * Mudou de lugar de verdade? CDN (Cloudflare, CloudFront) troca de IP o
     * tempo todo dentro da mesma rede; isso não é mudança. Conta quando
     * nenhuma faixa /24 se repete e a rede dona do IP também mudou.
     */
    private function moved(array $before, array $after, ?string $networkBefore, ?string $networkAfter): bool
    {
        $ranges = fn (array $ips) => array_unique(array_map(
            fn ($ip) => str_contains($ip, ':') ? implode(':', array_slice(explode(':', $ip), 0, 3)) : implode('.', array_slice(explode('.', $ip), 0, 3)),
            $ips,
        ));

        if ($before === [] || $after === []) {
            return $before !== $after;
        }

        if (array_intersect($ranges($before), $ranges($after))) {
            return false;
        }

        return $networkBefore === null || $networkAfter === null || $networkBefore !== $networkAfter;
    }

    /** Sumiu do DNS: registrável sem NS, ou subdomínio sem IP. */
    private function isGone(LookalikeDomain $lookalike): bool
    {
        return RegistrableDomain::of($lookalike->host) === $lookalike->host ? ($lookalike->ns ?? []) === [] : ($lookalike->ips ?? []) === [];
    }

    /** Situação pelo DNS e se o navegador precisa ir lá (sem visita, mudou de hospedagem ou print velho). */
    private function decide(LookalikeDomain $lookalike, array $previousIps, ?string $previousNetwork): bool
    {
        if ($this->isGone($lookalike)) {
            $lookalike->state = 'gone';

            return false;
        }

        $ips = $lookalike->ips ?? [];
        $publicIps = array_filter($ips, fn ($ip) => IpGuard::blockReason($ip) === null);
        $capture = $lookalike->lastCapture;
        $changed = $lookalike->exists && $this->moved($previousIps, $ips, $previousNetwork, $lookalike->hosting['network'] ?? null);
        // Página em branco (ou atrás de desafio) e domínio já denunciado: o navegador volta todo dia
        // para ver se o golpe apareceu (ou se caiu). Os outros, 1 vez por semana.
        $daily = in_array($lookalike->state, (array) config('secmon.lookalikes.daily_states', []), true) || $lookalike->reported();
        $stale = ! $capture?->captured_at || $capture->captured_at->lt($daily ? now()->subHours(20) : now()->subDays((int) config('secmon.lookalikes.capture_every_days', 7)));

        if ($changed) {
            $this->note("{$lookalike->host}: IP mudou (".(implode(', ', $previousIps) ?: 'nenhum').' → '.(implode(', ', $ips) ?: 'nenhum').').');
        }

        // Sem IP, ou com IP interno (10.x, 127.x): não há página para abrir.
        if ($ips === [] || count($publicIps) < count($ips)) {
            $lookalike->state = 'no_site';

            return false;
        }

        // O que o cliente marcou como dele não precisa de print toda semana.
        if ($lookalike->verdict === 'ours' && $capture && ! $changed) {
            return false;
        }

        if (Parking::byNameservers($lookalike->ns ?? []) && $capture) {
            $lookalike->state = 'parked';

            return $changed || $stale;
        }

        // Sem visita ainda, ou voltou a ter site depois de sumir: aguarda o navegador.
        if (! $capture || in_array($lookalike->state, ['gone', 'no_site'], true)) {
            $lookalike->state = 'pending';

            return true;
        }

        return $changed || $stale;
    }

    /**
     * 3 páginas do site do cliente nos 3 aparelhos, 1 vez por mês, para
     * comparar com os achados. Só com o termo v3 do passo 5, que autoriza
     * abrir essas páginas com um navegador.
     */
    private function reference(LookalikeWatch $watch, BrandContext $brand): void
    {
        $domain = $watch->domain;

        if (! $domain->allowsReferenceCapture()) {
            if ($watch->reference_status !== 'no_consent') {
                $watch->forceFill(['reference_status' => 'no_consent'])->save();
            }
            $this->note('Prints do seu site para comparação: precisam do termo '.config('secmon.alerts.consent_version').' (passo 5 da entrevista).');

            return;
        }

        if ($watch->reference_status === 'pending' || ! $watch->referenceDue()) {
            return;
        }

        // Com um teste de segurança no site, os prints esperam a próxima varredura (T10).
        if (DomainActivity::pentestActive($domain->domain)) {
            $this->note('Prints do seu site: adiados, porque há um teste de segurança em andamento nele.');

            return;
        }

        $this->browser->enqueueReference($watch, 'https://'.$domain->domain.'/', $brand->ownDomains);
        $this->note('Prints do seu site (página inicial e duas linkadas nela, nos 3 aparelhos) pedidos ao navegador, para comparar com os achados.');
    }

    private function note(string $line): void
    {
        $this->log[] = ['at' => now()->toIso8601String(), 'text' => $line];
    }

    private function finish(LookalikeWatch $watch, string $status, ?string $error, bool $full): void
    {
        if ($error) {
            $this->note($error);
        }

        $attributes = ['scan_status' => $status, 'last_error' => $error, 'last_log' => $this->log];

        if ($full) {
            $attributes['last_scan_at'] = now();
            $attributes['next_scan_at'] = self::nextScanAt();
        }

        $watch->forceFill($attributes)->save();
    }

    /** O próximo `scan_at` (01:00 de Brasília) depois de agora, no fuso do banco. */
    public static function nextScanAt(): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) config('secmon.lookalikes.scan_at', '01:00')));

        $next = now(config('app.schedule_timezone'))->setTime($hour, $minute);

        return ($next->isFuture() ? $next : $next->addDay())->setTimezone(config('app.timezone'));
    }

    /** @return Collection<int, LookalikeWatch> */
    public static function due(): Collection
    {
        return LookalikeWatch::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('next_scan_at')->orWhere('next_scan_at', '<=', now()))
            ->get();
    }
}
