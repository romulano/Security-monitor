<?php

namespace App\Services\Alerts;

use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\GithubFinding;
use App\Models\GithubMonitor;
use App\Models\LookalikeAlert;
use App\Models\LookalikeDomain;
use App\Models\LookalikeWatch;
use App\Models\NewsCase;
use App\Models\NotificationChannel;
use App\Services\Company\Destinations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Conteúdo do resumo diário de um canal: das HH:MM de ontem às HH:MM de hoje
 * no fuso do cliente. Primeiro o que casou com o perfil, depois (no modo
 * "tudo") o panorama dos critical e high do período, e por fim o Radar de
 * domínios e o GitHub. Cada tipo só entra se estiver ligado na Central.
 */
class DigestBuilder
{
    private const TZ_LABELS = [
        'America/Sao_Paulo' => 'Brasília',
        'America/Manaus' => 'Manaus',
        'America/Fortaleza' => 'Fortaleza',
        'Europe/Lisbon' => 'Lisboa',
        'UTC' => 'UTC',
    ];

    public function window(NotificationChannel $channel, Carbon $localDate): array
    {
        $end = Carbon::parse($localDate->format('Y-m-d').' '.$channel->digest_time, $channel->timezone)->utc();

        return [$end->copy()->subDay(), $end];
    }

    public function build(NotificationChannel $channel, Carbon $localDate): array
    {
        [$start, $end] = $this->window($channel, $localDate);
        // Todos os alertas ativos que mandam para este canal (um por produto ou site) vão no mesmo resumo: no pessoal, os
        // do dono; no da empresa, os do escopo do canal (Destinations, E5b1).
        $profiles = $this->created(AlertProfile::query(), $channel)->where('status', 'active')->whereNotNull('completed_at')->get();
        $many = $this->created(AlertProfile::query(), $channel)->count() > 1;
        $tz = $channel->timezone;

        // Cliente só com o monitoramento do GitHub (sem alerta ativo): o resumo não tem notícias.
        $news = $channel->wants('news') && $profiles->isNotEmpty();
        $inWindow = fn ($q) => $q->where('is_published', true)->where('latest_at', '>=', $start)->where('latest_at', '<', $end);

        $counts = ! $news ? [] : NewsCase::query()->tap($inWindow)
            ->selectRaw("coalesce(criticity, 'low') as level, count(*) as n")
            ->groupBy('level')
            ->pluck('n', 'level')
            ->map(fn ($n) => (int) $n)
            ->all();

        $mine = [];

        if ($news && $profiles->isNotEmpty()) {
            $matches = AlertMatch::query()
                ->whereIn('profile_id', $profiles->pluck('id'))
                ->relevant()
                ->where('matched_at', '>=', $start)
                ->where('matched_at', '<', $end)
                // Fora: histórico de antes de ativar e o que a IA ainda está julgando.
                ->where(fn ($q) => $q->whereNull('delivery_note')->orWhereNotIn('delivery_note', ['backfill', 'ai_review']))
                // Nem a matéria escondida do feed público (fora de segurança), como no panorama.
                ->whereHas('case', fn ($q) => $q->where('is_published', true))
                ->with(['case', 'profile:id,name,company_name'])
                ->latest('matched_at')
                ->get()
                ->groupBy('case_id');

            foreach ($matches as $group) {
                $sent = $group->firstWhere('delivery_status', 'sent');
                $alerts = $many ? $group->map(fn (AlertMatch $m) => $m->profile->displayName())->unique()->implode(' + ').': ' : '';

                $mine[] = [
                    'case' => $group->first()->case,
                    'criticity' => $group->first()->case->criticity,
                    'why' => $alerts.$group->first()->reasonTerms(),
                    'sent_at' => $sent?->notified_at?->timezone($tz)->format('H:i'),
                ];
            }

            usort($mine, fn ($a, $b) => $this->rank($a['criticity']) <=> $this->rank($b['criticity']));
            $mine = array_slice($mine, 0, 10);
        }

        $panorama = [];
        $panoramaTotal = 0;

        if ($news && $channel->digest_scope === 'all') {
            $base = NewsCase::query()->tap($inWindow)->whereIn('criticity', ['critical', 'high']);
            $panoramaTotal = (clone $base)->count();
            $panorama = $base
                ->whereNotIn('id', array_map(fn ($m) => $m['case']->id, $mine))
                ->orderByRaw("case criticity when 'critical' then 0 else 1 end")
                ->orderByDesc('source_count')
                ->orderByDesc('latest_at')
                ->limit(10)
                ->get()
                ->all();
        }

        $radar = $channel->wants('radar') ? $this->radar($channel, $start, $end, $tz) : [];
        $github = $channel->wants('github') ? $this->github($channel, $start, $end, $tz) : [];

        return [
            'news' => $news,
            'radar' => $radar,
            'github' => $github,
            // "Nada novo no Radar de domínios nem no GitHub" e o botão quando não há notícias.
            'quiet_label' => $this->quietLabel($channel),
            'open' => match (true) {
                $news => ['Abrir o feed', route('feed.index')],
                $radar !== [] || ($github === [] && $channel->wants('radar') && $this->watching($channel)) => ['Abrir o Radar', route('alerts.lookalikes')],
                default => ['Abrir o GitHub', route('alerts.github')],
            },
            'date' => $localDate,
            'start' => $start,
            'end' => $end,
            'counts' => $counts,
            'total' => array_sum($counts),
            'mine' => $mine,
            'panorama' => $panorama,
            'panorama_total' => $panoramaTotal,
            'scope' => $channel->digest_scope,
            'lang' => $profiles->first()?->language ?? 'pt',
            'days' => $channel->daysLabel() === 'todos os dias' ? 'todos os dias' : 'de '.$channel->daysLabel(),
            'time' => $channel->digest_time,
            'tz_label' => self::TZ_LABELS[$tz] ?? $tz,
            // O webhook manda os dias (1 = segunda) e o fuso em campos.
            'weekdays' => array_map('intval', $channel->digest_days ?? []),
            'timezone' => $tz,
        ];
    }

    /**
     * Radar de domínios do período: os avisos (já enviados ou segurados) e os
     * domínios novos para vigiar, que não viram aviso na hora.
     *
     * @return list<array{level: string, label: string, host: string, url: string, note: string}>
     */
    private function radar(NotificationChannel $channel, Carbon $start, Carbon $end, string $tz): array
    {
        $watchIds = $this->created(LookalikeWatch::query(), $channel)
            ->where('status', 'active')
            ->pluck('id');

        if ($watchIds->isEmpty()) {
            return [];
        }

        $items = [];

        $alerts = LookalikeAlert::query()
            ->whereHas('lookalike', fn ($q) => $q->whereIn('watch_id', $watchIds))
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->with('lookalike')
            ->latest()
            ->get()
            ->unique('lookalike_id');

        foreach ($alerts as $alert) {
            $items[] = [
                'level' => $alert->risk,
                'label' => mb_strtoupper(LookalikeDomain::RISK_LABELS[$alert->risk] ?? $alert->risk),
                'host' => $alert->lookalike->displayHost(),
                'url' => route('alerts.lookalikes.show', $alert->lookalike),
                'note' => collect([
                    $alert->ai_reason ?: collect($alert->lookalike->signals ?? [])->where('points', '>', 0)->sortByDesc('points')->first()['label'] ?? null,
                    match ($alert->status) {
                        'sent' => 'já enviado às '.$alert->notified_at?->timezone($tz)->format('H:i'),
                        'held' => 'segurado pela IA',
                        default => null,
                    },
                ])->filter()->implode(' · '),
            ];
        }

        $new = LookalikeDomain::query()
            ->whereIn('watch_id', $watchIds)
            ->where('first_seen_at', '>=', $start)->where('first_seen_at', '<', $end)
            ->where('risk', 'watch')
            ->whereNull('verdict')
            ->whereNotIn('id', $alerts->pluck('lookalike_id'))
            ->latest('first_seen_at')
            ->limit(8)
            ->get();

        foreach ($new as $lookalike) {
            $items[] = [
                'level' => 'watch',
                'label' => 'VIGIAR',
                'host' => $lookalike->displayHost(),
                'url' => route('alerts.lookalikes.show', $lookalike),
                'note' => collect([
                    $lookalike->registeredAgo() ? 'registrado '.$lookalike->registeredAgo() : 'novo',
                    $lookalike->hasMx() ? 'recebe e-mail' : null,
                    $lookalike->stateLabel(),
                ])->filter()->implode(', '),
            ];
        }

        return array_slice($items, 0, 10);
    }

    /**
     * Monitoramento do GitHub do período: os achados que chegaram ao nível do
     * cliente (enviados, segurados pela IA ou que não saíram na hora).
     *
     * @return list<array{level: string, label: string, host: string, url: string, note: string}>
     */
    private function github(NotificationChannel $channel, Carbon $start, Carbon $end, string $tz): array
    {
        // Na empresa o escopo pode ter vários monitoramentos (P6 do 5.2); na conta pessoal, um.
        $monitorIds = $this->created(GithubMonitor::query(), $channel)->where('status', 'active')->pluck('id');

        if ($monitorIds->isEmpty()) {
            return [];
        }

        return GithubFinding::query()->whereIn('monitor_id', $monitorIds)
            ->whereIn('status', ['sent', 'held', 'skipped', 'queued', 'failed'])
            // Achado novo no período, ou que mudou nele (e passou de novo pelas duas etapas).
            ->where(fn ($q) => $q->whereBetween('first_seen_at', [$start, $end])->orWhereBetween('changed_at', [$start, $end]))
            ->byLevel()
            ->limit(10)
            ->get()
            ->map(fn (GithubFinding $finding) => [
                'level' => $finding->level(),
                'label' => mb_strtoupper($finding->level()),
                'host' => $finding->location(),
                'url' => route('alerts.github.show', $finding),
                'note' => collect([
                    $finding->triage_reason ?: $finding->kindLabel(),
                    match ($finding->status) {
                        'sent' => 'já enviado às '.$finding->notified_at?->timezone($tz)->format('H:i'),
                        'held' => 'segurado pela IA',
                        default => null,
                    },
                ])->filter()->implode(' · '),
            ])
            ->all();
    }

    /** "no Radar de domínios", "no GitHub" ou os dois, conforme o que o canal recebe e o cliente vigia. */
    private function quietLabel(NotificationChannel $channel): string
    {
        $watching = array_keys(array_filter([
            'no Radar de domínios' => $channel->wants('radar') && $this->watching($channel),
            'no GitHub' => $channel->wants('github') && $this->created(GithubMonitor::query(), $channel)->where('status', 'active')->exists(),
        ]));

        return implode(' nem ', $watching) ?: 'no Radar de domínios';
    }

    private function watching(NotificationChannel $channel): bool
    {
        return $this->created(LookalikeWatch::query(), $channel)->where('status', 'active')->exists();
    }

    /** O que manda para este canal: a conta pessoal, o dela, como sempre; o canal da empresa, o do escopo dele. */
    private function created(Builder $query, NotificationChannel $channel): Builder
    {
        return app(Destinations::class)->sourcesFor($channel, $query);
    }

    private function rank(?string $level): int
    {
        return ['critical' => 0, 'high' => 1, 'medium' => 2][$level] ?? 3;
    }
}
