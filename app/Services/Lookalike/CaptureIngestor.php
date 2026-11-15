<?php

namespace App\Services\Lookalike;

use App\Jobs\ReviewLookalikeAlert;
use App\Models\LookalikeAlert;
use App\Models\LookalikeCapture;
use App\Models\LookalikeDomain;
use App\Models\LookalikeWatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Lê as respostas do navegador (outbox/): grava cada visita com os prints dos
 * 3 aparelhos, recalcula o nível do domínio e cria o aviso quando ele aparece
 * já em Crítico ou Alto, ou sobe para um deles. As respostas do pedido de
 * referência viram os prints do site do cliente.
 *
 * A visita que muda o nível fica guardada para sempre: é a prova de "no dia
 * 12 estava parado, no dia 15 virou cópia do login".
 */
class CaptureIngestor
{
    private const RANK = ['critical' => 0, 'high' => 1, 'watch' => 2, 'low' => 3];

    public function __construct(private BrowserQueue $browser, private RiskScorer $scorer) {}

    /** @return array{captures: int, changed: int, alerts: int, references: int, expired: int} */
    public function ingest(): array
    {
        $totals = ['captures' => 0, 'changed' => 0, 'alerts' => 0, 'references' => 0];
        $contexts = [];

        foreach ($this->browser->results() as $batch) {
            if (($batch['mode'] ?? null) === 'reference') {
                $totals['references'] += (int) $this->applyReference($batch);

                continue;
            }

            foreach ($batch['results'] ?? [] as $result) {
                $capture = LookalikeCapture::query()->with('lookalike.watch.domain')->find((int) ($result['id'] ?? 0));

                if (! $capture || $capture->status !== 'queued' || $capture->batch !== ($batch['batch'] ?? null)) {
                    continue;
                }

                // O navegador ficou sem tempo antes de chegar nele: não é falha do site, pede de novo depois.
                if ($result['skipped'] ?? false) {
                    $capture->update(['status' => 'failed', 'error' => $this->clip($result['error'] ?? 'não visitado', 500)]);

                    continue;
                }

                $watch = $capture->lookalike->watch;
                $brand = $contexts[$watch->id] ??= BrandContext::for($watch);
                [$changed, $alert] = $this->apply($capture, $result, $brand);
                $totals['captures']++;
                $totals['changed'] += (int) $changed;
                $totals['alerts'] += (int) ($alert !== null);
            }
        }

        return $totals + ['expired' => $this->expireStale()];
    }

    /**
     * Grava uma visita e recalcula o domínio.
     *
     * @return array{0: bool, 1: ?LookalikeAlert} se o nível mudou, e o aviso criado
     */
    public function apply(LookalikeCapture $capture, array $result, BrandContext $brand): array
    {
        $ok = (bool) ($result['ok'] ?? false);
        $text = mb_substr(trim((string) ($result['text'] ?? '')), 0, 4000);
        $devices = [];
        $shots = [];

        foreach (array_keys(LookalikeCapture::DEVICES) as $device) {
            $seen = (array) ($result['devices'][$device] ?? []);

            if ($seen === []) {
                continue;
            }

            $devices[$device] = array_filter([
                'ok' => (bool) ($seen['ok'] ?? false),
                'status' => isset($seen['status']) ? (int) $seen['status'] : null,
                'final_url' => $this->clip($seen['final_url'] ?? null, 500),
                'title' => $this->clip($seen['title'] ?? null, 300),
                'text' => $this->clip($seen['text'] ?? null, 1500),
                'text_length' => (int) ($seen['text_length'] ?? 0),
                'images' => (int) ($seen['images'] ?? 0),
                'forms' => $this->forms($seen['forms'] ?? []),
                'own_requests' => (int) ($seen['own_requests'] ?? 0),
                'certificate_error' => $this->clip($seen['certificate_error'] ?? null, 60),
                'error' => $this->clip($seen['error'] ?? null, 300),
            ], fn ($v) => $v !== null);

            $similar = is_array($seen['similar'] ?? null) ? ['page' => (int) ($seen['similar']['page'] ?? 0), 'score' => max(0, min(100, (int) ($seen['similar']['score'] ?? 0)))] : null;
            $first = $this->shot($seen['first'] ?? null);
            $second = $this->shot($seen['second'] ?? null);

            if ($first || $second) {
                $shots[$device] = [
                    'first' => $first ?? $second,
                    'second' => $first ? $second : null,
                    'changed' => (bool) ($seen['changed'] ?? false) && $first && $second,
                    'similar' => $similar,
                ];
            }
        }

        $capture->fill([
            'status' => 'done',
            'captured_at' => isset($result['captured_at']) ? Carbon::parse($result['captured_at']) : now(),
            'final_url' => $this->clip($result['final_url'] ?? null, 2000),
            'http_status' => isset($result['status']) ? (int) $result['status'] : null,
            'title' => $this->clip($result['title'] ?? null, 300),
            'text_excerpt' => $text !== '' ? $text : null,
            'html_hash' => $this->clip($result['html_sha256'] ?? null, 64),
            'shots' => $shots,
            'error' => $this->clip($result['error'] ?? null, 500),
            'data' => [
                'ok' => $ok,
                'text_length' => (int) ($result['text_length'] ?? mb_strlen($text)),
                'images' => (int) ($result['images'] ?? 0),
                'forms' => $this->forms($result['forms'] ?? []),
                'own_requests' => array_slice(array_values(array_filter((array) ($result['own_requests'] ?? []), 'is_array')), 0, 30),
                'own_navigation' => $this->clip($result['own_navigation'] ?? null, 2000),
                'certificate_error' => $this->clip($result['certificate_error'] ?? null, 60),
                'resource_hosts' => $this->hosts($result['resource_hosts'] ?? []),
                'link_hosts' => $this->hosts($result['link_hosts'] ?? []),
                'redirects' => array_slice(array_map(fn ($u) => $this->clip($u, 500), (array) ($result['redirects'] ?? [])), 0, 10),
                'blocked' => array_slice(array_map(fn ($u) => $this->clip($u, 300), (array) ($result['blocked'] ?? [])), 0, 20),
                'mobile_same' => (bool) ($result['mobile_same'] ?? false),
                'devices' => $devices,
                'ms' => (int) ($result['ms'] ?? 0),
            ],
        ]);

        $lookalike = $capture->lookalike;
        $previous = ['state' => $lookalike->state, 'risk' => $lookalike->risk];
        $capture->state = $this->scorer->stateFromCapture($capture, $brand);
        $lookalike->state = $capture->state;
        $lookalike->setRelation('lastCapture', $capture);
        $capture->save();
        $lookalike->last_capture_id = $capture->id;

        $changed = $this->scorer->apply($lookalike, $brand);
        $alert = $this->considerAlert($lookalike, $capture, $previous, $changed, $brand);
        $lookalike->save();

        $capture->forceFill([
            'risk' => $lookalike->risk,
            'score' => $lookalike->score,
            // A primeira visita e cada mudança de nível ficam como prova.
            'keep' => $changed || $lookalike->captures()->where('status', 'done')->whereKeyNot($capture->id)->doesntExist(),
        ])->save();

        if ($alert) {
            $alert->save();
            ReviewLookalikeAlert::dispatch($alert->id);
        }

        return [$changed, $alert];
    }

    /**
     * Aviso na hora: domínio que aparece já em Crítico ou Alto, ou que sobe para
     * um deles. "Vigiar" vai só no resumo diário; o que o cliente disse que é dele
     * nunca avisa. O que ele marcou como "não é ameaça" volta a avisar se subir.
     */
    private function considerAlert(LookalikeDomain $lookalike, LookalikeCapture $capture, array $previous, bool $changed, BrandContext $brand): ?LookalikeAlert
    {
        if ($lookalike->verdict === 'ours' || ! in_array($lookalike->risk, ['critical', 'high'], true) || $lookalike->watch?->status !== 'active') {
            return null;
        }

        $first = $previous['state'] === 'pending';
        $raised = $changed && (self::RANK[$lookalike->risk] ?? 9) < (self::RANK[$previous['risk']] ?? 9);

        if (! $first && ! $raised) {
            return null;
        }

        if ($raised && $lookalike->verdict === 'safe') {
            $lookalike->forceFill(['verdict' => null, 'verdict_at' => null]);
        } elseif ($lookalike->verdict === 'safe') {
            return null;
        }

        return new LookalikeAlert([
            'lookalike_id' => $lookalike->id,
            'capture_id' => $capture->id,
            'kind' => $first ? 'new' : 'raised',
            'risk' => $lookalike->risk,
            'previous_risk' => $first ? null : $previous['risk'],
            'status' => 'ai_review',
            'photo' => AlertPhoto::pick($capture, $brand) ?? [],
        ]);
    }

    /** Prints das 3 páginas do site do cliente. Os antigos saem só quando os novos chegaram. */
    private function applyReference(array $batch): bool
    {
        $watch = LookalikeWatch::query()->find((int) ($batch['watch'] ?? 0));

        if (! $watch) {
            return false;
        }

        $dir = "ref/{$watch->id}/";
        $pages = [];

        foreach (array_slice((array) ($batch['pages'] ?? []), 0, 5) as $page) {
            $shots = [];

            foreach (array_keys(LookalikeCapture::DEVICES) as $device) {
                $path = $this->shot($page['shots'][$device] ?? null);

                if ($path && str_starts_with($path, $dir)) {
                    $shots[$device] = $path;
                }
            }

            if ($shots !== [] && filter_var($page['url'] ?? null, FILTER_VALIDATE_URL)) {
                $pages[] = ['url' => $this->clip($page['url'], 500), 'title' => $this->clip($page['title'] ?? null, 300), 'shots' => $shots];
            }
        }

        if ($pages === []) {
            $watch->forceFill(['reference_status' => 'failed', 'reference' => array_merge($watch->reference ?? [], [
                'error' => $this->clip($batch['error'] ?? ($batch['pages'][0]['error'] ?? 'o site não abriu no navegador'), 300),
            ])])->save();

            return false;
        }

        $kept = array_merge(...array_map(fn ($p) => array_values($p['shots']), $pages));

        foreach ($watch->referencePages() as $old) {
            foreach ($old['shots'] as $path) {
                if (! in_array($path, $kept, true) && ($file = LookalikeCapture::fileIn($path))) {
                    File::delete($file);
                }
            }
        }

        $watch->forceFill([
            'reference' => ['pages' => $pages, 'captured_at' => $batch['captured_at'] ?? now()->toIso8601String(), 'error' => null],
            'reference_at' => now(),
            'reference_status' => 'done',
        ])->save();

        return true;
    }

    /** Recalcula situação e nível de todos a partir da última visita (depois de mudar as regras). Não conta como mudança. */
    public function rescore(): int
    {
        $count = 0;
        $contexts = [];

        LookalikeDomain::query()->with(['lastCapture', 'watch.domain'])->chunkById(200, function ($lookalikes) use (&$count, &$contexts) {
            foreach ($lookalikes as $lookalike) {
                $brand = $contexts[$lookalike->watch_id] ??= BrandContext::for($lookalike->watch);

                if ($lookalike->lastCapture?->status === 'done' && ! in_array($lookalike->state, ['gone', 'no_site'], true)) {
                    $lookalike->state = $this->scorer->stateFromCapture($lookalike->lastCapture, $brand);
                    $lookalike->lastCapture->forceFill(['state' => $lookalike->state])->save();
                }

                $risk = $lookalike->risk;
                $this->scorer->apply($lookalike, $brand);
                $lookalike->forceFill(['risk_changed_at' => $lookalike->getOriginal('risk_changed_at'), 'previous_risk' => $lookalike->getOriginal('previous_risk')])->save();
                $lookalike->lastCapture?->forceFill(['risk' => $lookalike->risk, 'score' => $lookalike->score])->save();
                $count += $risk !== $lookalike->risk ? 1 : 0;
            }
        });

        return $count;
    }

    /** Pedidos que o navegador nunca respondeu viram falha (e podem ser pedidos de novo). */
    public function expireStale(): int
    {
        LookalikeWatch::query()
            ->where('reference_status', 'pending')
            ->where('updated_at', '<', now()->subHours(BrowserQueue::STALE_HOURS))
            ->update(['reference_status' => 'failed', 'updated_at' => now()]);

        return LookalikeCapture::query()
            ->where('status', 'queued')
            ->where('requested_at', '<', now()->subHours(BrowserQueue::STALE_HOURS))
            ->update(['status' => 'failed', 'error' => 'o navegador não respondeu', 'updated_at' => now()]);
    }

    /**
     * Apaga prints velhos que não marcaram nada (os de mudança de nível e os de aviso ficam).
     *
     * NOT EXISTS, não NOT IN: com centenas de milhares de `last_capture_id` o NOT IN estoura o
     * work_mem (4 MB) e vira laço quadrático (medido com 600 mil capturas: cancelado depois de 7 min;
     * com NOT EXISTS e o índice de last_capture_id, 2,7 s, com o mesmo resultado). Lê só o id e os
     * caminhos dos prints, em páginas, em vez de carregar a tabela inteira na memória.
     *
     * @return int quantas capturas foram apagadas
     */
    public function prune(): int
    {
        $deleted = 0;

        LookalikeCapture::query()
            ->select(['id', 'shots'])
            ->where('keep', false)
            ->where('created_at', '<', now()->subDays((int) config('secmon.lookalikes.keep_days', 90)))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('lookalike_domains')->whereColumn('lookalike_domains.last_capture_id', 'lookalike_captures.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('lookalike_alerts')->whereColumn('lookalike_alerts.capture_id', 'lookalike_captures.id'))
            ->lazyById(200)
            ->each(function (LookalikeCapture $capture) use (&$deleted) {
                File::delete($capture->files());
                $capture->delete();
                $deleted++;
            });

        return $deleted;
    }

    /** Caminho relativo a shots/, sem subir de pasta. */
    private function shot(mixed $path): ?string
    {
        $path = is_string($path) ? ltrim($path, '/') : '';

        return $path !== '' && ! str_contains($path, '..') && preg_match('#^[a-z0-9/_.-]+\.(jpe?g|png|webp)$#i', $path) ? $path : null;
    }

    private function forms(mixed $forms): array
    {
        return array_map('intval', array_intersect_key((array) $forms, array_flip(['password', 'email', 'card', 'cpf', 'pix', 'inputs'])));
    }

    private function hosts(mixed $hosts): array
    {
        return array_slice(array_values(array_unique(array_filter(array_map(
            fn ($h) => is_string($h) ? strtolower(mb_substr($h, 0, 253)) : null,
            (array) $hosts,
        )))), 0, 60);
    }

    private function clip(mixed $value, int $max): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, $max) : null;
    }
}
