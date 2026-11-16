<?php

namespace App\Services\Alerts;

use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\GithubFinding;
use App\Models\GithubMonitor;
use App\Models\LookalikeAlert;
use App\Models\LookalikeDomain;
use App\Models\LookalikeWatch;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Github\GithubJudge;
use App\Services\Lookalike\LookalikeReviewer;
use App\Support\Settings;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * /alertas: o agregador. Tudo o que as três fontes acharam para o cliente
 * (notícias, Radar de domínios e GitHub) numa lista só, com o histórico
 * inteiro, filtrada pela fonte e pela situação (os mesmos filtros da aba
 * Notícias), e com o motivo, a avaliação e o reenvio em cada linha. No topo,
 * a situação de cada fonte e dos notificadores.
 */
class AlertInbox
{
    /** `?fonte=`. */
    public const SOURCES = [
        'todas' => 'Todas',
        'noticias' => 'Notícias',
        'radar' => 'Radar',
        'github' => 'GitHub',
    ];

    /** `?filtro=`: a situação, igual para as três fontes. */
    public const FILTERS = AlertPanel::FILTERS;

    /** `?fonte=` → a chave de cada fonte nas linhas. */
    private const KEYS = ['noticias' => 'news', 'radar' => 'radar', 'github' => 'github'];

    private const PER_PAGE = 25;

    /** Achado do GitHub que chegou à etapa de envio (abaixo do nível e descartado ficam só na página do GitHub). */
    private const GITHUB_LISTED = ['ai_review', 'held', 'queued', 'sent', 'failed', 'skipped'];

    /** Radar e GitHub: o que a IA deixou passar (saiu, ou devia sair), que o cliente pode avaliar. */
    private const DELIVERED = ['sent', 'queued', 'skipped', 'failed'];

    /** Radar e GitHub: "não saiu na hora, vai no resumo diário". */
    private const DIGEST_NOTES = ['realtime_off', 'quiet_hours', 'throttled'];

    public function page(User $user, string $source, string $filter, int $page): array
    {
        // O que a conta vê (DESENHO-5.2 §4): a pessoal, o dela; a de empresa, o da empresa e dos setores dela.
        $profiles = AlertProfile::query()->visibleTo($user)->oldest('id')->get();
        $completed = $profiles->whereNotNull('completed_at');
        $watches = LookalikeWatch::query()->visibleTo($user)->with('domain')->oldest('id')->get();
        $ready = $watches->filter->isCompleted();
        $monitors = GithubMonitor::query()->visibleTo($user)->oldest('id')->get();
        $monitor = $monitors->first();
        $channels = $user->notificationChannels()->get()
            ->sortBy(fn (NotificationChannel $channel) => array_search($channel->type, array_keys(NotificationChannel::APPS), true))
            ->values();

        $bases = $this->bases($completed, $ready, $monitors);

        // Quantos há em cada fonte × situação: os números dos dois filtros.
        $matrix = [];
        foreach ($bases as $key => $base) {
            foreach (array_keys(self::FILTERS) as $value) {
                $matrix[$key][$value] = (clone $base)->tap($this->scope($key, $value))->count();
            }
        }

        $selected = $source === 'todas' ? array_keys($bases) : array_values(array_intersect([self::KEYS[$source]], array_keys($bases)));
        $total = collect($selected)->sum(fn (string $key) => $matrix[$key][$filter]);
        $rows = $this->rows($bases, $selected, $filter, $page);

        // Dos últimos 30 dias: o que virou alerta (a IA não segurou), o que o cliente marcou como não relevante e o que a IA segurou.
        $recent = collect($bases)->map(function (Builder $base, string $key) {
            $all = (clone $base)->tap($this->since($key))->count();
            $held = (clone $base)->tap($this->since($key))->tap($this->scope($key, 'segurados-pela-ia'))->count();

            return [
                'alerts' => $all - $held,
                'irrelevant' => (clone $base)->tap($this->since($key))->tap($this->scope($key, 'nao-relevantes'))->count(),
                'held' => $held,
            ];
        });

        return [
            'integrated' => $completed->isNotEmpty() || $ready->isNotEmpty() || $monitors->contains->isCompleted(),
            'sources' => $this->sources($profiles, $watches, $monitor, $recent),
            'channels' => $channels,
            'items' => new LengthAwarePaginator(
                $this->hydrate($rows, $completed, $ready),
                $total,
                self::PER_PAGE,
                $page,
                ['path' => route('alerts.index'), 'query' => array_filter([
                    'fonte' => $source === 'todas' ? null : $source,
                    'filtro' => $filter === 'todos' ? null : $filter,
                ])],
            ),
            'source' => $source,
            'filter' => $filter,
            // Fonte: quantos há em cada uma na situação escolhida. Situação: quantos há em cada uma, nas fontes escolhidas.
            'sourceCounts' => collect(self::SOURCES)->map(fn ($label, string $value) => collect($value === 'todas' ? array_keys($bases) : array_intersect([self::KEYS[$value]], array_keys($bases)))
                ->sum(fn (string $key) => $matrix[$key][$filter]))->all(),
            'counts' => collect(self::FILTERS)->map(fn ($label, string $value) => collect($selected)->sum(fn (string $key) => $matrix[$key][$value]))->all(),
            'stats' => [
                'alerts' => $recent->sum('alerts'),
                'irrelevant' => $recent->sum('irrelevant'),
                'held' => $recent->sum('held'),
            ],
            'many' => $completed->count() > 1,
            'manyRadars' => $ready->count() > 1,
            'receivers' => collect(NotificationChannel::TYPES)
                ->map(fn ($label, string $type) => $channels->filter(fn (NotificationChannel $channel) => $channel->receives($type))->values()),
            // Para o "no Slack e no Discord" da situação de envio das notícias.
            'apps' => $channels->filter->wants('news')->map->appName()->join(', ', ' e ') ?: ($channels->map->appName()->join(', ', ' e ') ?: 'Slack'),
            'aiCuts' => [
                'news' => Settings::int('alerts.ai_review_threshold'),
                'radar' => app(LookalikeReviewer::class)->threshold(),
                'github' => app(GithubJudge::class)->threshold(),
            ],
        ];
    }

    /**
     * Os números de cada fonte integrada para a Integração: o que virou alerta
     * nos últimos 30 dias, o que espera a avaliação do cliente e o que a IA segurou.
     *
     * @return array<string, array{recent: int, pending: int, held: int}>
     */
    public function summary(User $user): array
    {
        $completed = AlertProfile::query()->visibleTo($user)->whereNotNull('completed_at')->get();
        $ready = LookalikeWatch::query()->visibleTo($user)->get()->filter->isCompleted();

        return collect($this->bases($completed, $ready, GithubMonitor::query()->visibleTo($user)->get()))
            ->map(fn (Builder $base, string $key) => [
                'recent' => (clone $base)->tap($this->since($key))->count()
                    - (clone $base)->tap($this->since($key))->tap($this->scope($key, 'segurados-pela-ia'))->count(),
                'pending' => (clone $base)->tap($this->scope($key, 'pendentes'))->count(),
                'held' => (clone $base)->tap($this->scope($key, 'segurados-pela-ia'))->count(),
            ])
            ->all();
    }

    /** Ameaças abertas nos Radares prontos: Crítico ou Alto, ainda no ar, sem "É nosso" nem "Não é ameaça". */
    public function threats(Collection $ready): int
    {
        return $ready->isEmpty() ? 0 : LookalikeDomain::query()
            ->whereIn('watch_id', $ready->pluck('id'))
            ->whereIn('risk', ['critical', 'high'])
            ->where('state', '!=', 'gone')
            ->where(fn ($q) => $q->whereNull('verdict')->orWhere('verdict', 'threat'))
            ->count();
    }

    /**
     * O que cada fonte integrada já achou, antes do filtro de situação.
     *
     * @return array<string, Builder>
     */
    private function bases(Collection $completed, Collection $ready, Collection $monitors): array
    {
        return array_filter([
            // Notícias: o que casou com os alertas concluídos, inclusive o que a IA segurou (como em Notícias).
            'news' => $completed->isEmpty() ? null : AlertMatch::query()
                ->whereIn('profile_id', $completed->pluck('id'))
                ->where(fn ($q) => $q->whereNull('suppressed_reason')->orWhere('suppressed_reason', 'ai_low_relevance')),
            // Radar: cada aviso (domínio novo já em Crítico ou Alto, ou que subiu de nível).
            'radar' => $ready->isEmpty() ? null : LookalikeAlert::query()
                ->whereHas('lookalike', fn ($q) => $q->whereIn('watch_id', $ready->pluck('id'))),
            'github' => ($done = $monitors->filter->isCompleted())->isNotEmpty() ? GithubFinding::query()
                ->whereIn('monitor_id', $done->pluck('id'))
                ->whereIn('status', self::GITHUB_LISTED) : null,
        ]);
    }

    /**
     * A situação em cada fonte. Notícias: os filtros da aba Notícias. Radar e
     * GitHub: o mesmo sentido, com a avaliação de cada um ("É golpe" conta
     * como relevante; "É nosso" e "Não é ameaça", como não relevante). O voto
     * do cliente vence a IA nos dois sentidos.
     */
    private function scope(string $key, string $filter): Closure
    {
        $verdict = fn (Builder $q, Closure $where) => $q->whereHas('lookalike', $where);

        return match ($key) {
            'news' => match ($filter) {
                'na-hora' => fn ($q) => $q->where('delivery_status', 'sent'),
                'resumo' => fn ($q) => $q->where('delivery_status', 'skipped')->whereIn('delivery_note', ['realtime_off', 'no_channel']),
                'pendentes' => fn ($q) => $q->whereNull('suppressed_reason')->whereNull('feedback')
                    ->where(fn ($q) => $q->whereNull('delivery_note')->orWhere('delivery_note', '!=', 'ai_review')),
                'relevantes' => fn ($q) => $q->where(fn ($q) => $q->where('feedback', 'relevant')->orWhere(fn ($q) => $q
                    ->whereNull('suppressed_reason')
                    ->where(fn ($q) => $q->whereNull('feedback')->orWhere('feedback', '!=', 'irrelevant')))),
                'nao-relevantes' => fn ($q) => $q->where('feedback', 'irrelevant'),
                'segurados-pela-ia' => fn ($q) => $q->where('suppressed_reason', 'ai_low_relevance'),
                default => fn ($q) => $q,
            },
            'radar' => match ($filter) {
                'na-hora' => fn ($q) => $q->where('status', 'sent'),
                'resumo' => fn ($q) => $q->where('status', 'skipped')->whereIn('note', self::DIGEST_NOTES),
                'pendentes' => fn ($q) => $verdict($q->whereIn('status', self::DELIVERED), fn ($q) => $q->whereNull('verdict')),
                'relevantes' => fn ($q) => $q->where(fn ($q) => $verdict($q, fn ($q) => $q->where('verdict', 'threat'))
                    ->orWhere(fn ($q) => $verdict($q->whereIn('status', self::DELIVERED), fn ($q) => $q->whereNull('verdict')))),
                'nao-relevantes' => fn ($q) => $verdict($q, fn ($q) => $q->whereIn('verdict', ['ours', 'safe'])),
                'segurados-pela-ia' => fn ($q) => $q->where('status', 'held'),
                default => fn ($q) => $q,
            },
            default => match ($filter) {
                'na-hora' => fn ($q) => $q->where('status', 'sent'),
                'resumo' => fn ($q) => $q->where('status', 'skipped')->whereIn('note', self::DIGEST_NOTES),
                'pendentes' => fn ($q) => $q->whereIn('status', self::DELIVERED)->whereNull('feedback'),
                'relevantes' => fn ($q) => $q->where(fn ($q) => $q->where('feedback', 'relevant')
                    ->orWhere(fn ($q) => $q->whereIn('status', self::DELIVERED)->whereNull('feedback'))),
                'nao-relevantes' => fn ($q) => $q->where('feedback', 'irrelevant'),
                'segurados-pela-ia' => fn ($q) => $q->where('status', 'held'),
                default => fn ($q) => $q,
            },
        };
    }

    /** A data da linha em cada fonte (a mesma que ordena a lista). */
    private function at(string $key): string
    {
        return match ($key) {
            'news' => 'alert_matches.matched_at',
            'radar' => 'lookalike_alerts.created_at',
            default => 'coalesce(github_findings.notified_at, github_findings.first_seen_at)',
        };
    }

    private function since(string $key): Closure
    {
        return fn ($q) => $q->whereRaw($this->at($key).' >= ?', [now()->subDays(30)]);
    }

    /**
     * A página pedida, já na ordem: as fontes escolhidas juntas numa consulta
     * só (UNION), mais novas primeiro. Cada linha traz só a fonte e o id.
     */
    private function rows(array $bases, array $selected, string $filter, int $page): Collection
    {
        $parts = collect($selected)->map(fn (string $key) => (clone $bases[$key])
            ->tap($this->scope($key, $filter))
            ->toBase()
            ->selectRaw("'{$key}' as source, ".$bases[$key]->getModel()->getTable().'.id as id, '.$this->at($key).' as at'));

        if ($parts->isEmpty()) {
            return collect();
        }

        $union = $parts->shift();
        $parts->each(fn ($part) => $union->unionAll($part));

        return DB::query()->fromSub($union, 'inbox')
            ->orderByDesc('at')->orderBy('source')->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)
            ->get();
    }

    /**
     * As linhas da página com o modelo de cada uma, carregado com o que a
     * linha mostra.
     *
     * @return Collection<int, array{source: string, model: mixed, watch: ?LookalikeWatch}>
     */
    private function hydrate(Collection $rows, Collection $completed, Collection $ready): Collection
    {
        $ids = $rows->groupBy('source')->map(fn (Collection $group) => $group->pluck('id')->all());

        $models = [
            'news' => AlertMatch::query()->whereIn('id', $ids['news'] ?? [])
                ->with(['case:id,uuid,title,title_pt,criticity', 'profile:id,name,company_name,language'])->get()->keyBy('id'),
            'radar' => LookalikeAlert::query()->whereIn('id', $ids['radar'] ?? [])->with('lookalike')->get()->keyBy('id'),
            'github' => GithubFinding::query()->whereIn('id', $ids['github'] ?? [])->get()->keyBy('id'),
        ];

        return $rows
            ->map(fn ($row) => ($model = $models[$row->source][$row->id] ?? null) ? [
                'source' => $row->source,
                'model' => $model,
                'watch' => $row->source === 'radar' ? $ready->firstWhere('id', $model->lookalike?->watch_id) : null,
            ] : null)
            ->filter()
            ->values();
    }

    /**
     * Situação de cada fonte para os cartões do topo: estado, uma linha de
     * resumo e para onde o cartão leva (a página da fonte, dentro da Integração).
     *
     * @return array<string, array{state: string, tone: string, line: string, url: string}>
     */
    private function sources(Collection $profiles, Collection $watches, ?GithubMonitor $monitor, Collection $recent): array
    {
        $count = fn (string $key) => $recent[$key]['alerts'] ?? 0;

        $completed = $profiles->whereNotNull('completed_at');
        $news = match (true) {
            $completed->isEmpty() && $profiles->isEmpty() => ['não integrado', 'off', 'Falhas e ataques que afetam a sua stack.', route('alerts.integrations')],
            $completed->isEmpty() => ['rascunho', 'warn', 'Entrevista no passo '.$profiles->first()->step_reached.'.', route('alerts.news')],
            $completed->every(fn (AlertProfile $p) => $p->isPaused()) => ['pausado', 'off', $this->count($completed->count(), 'alerta', 'alertas').' pausado(s).', route('alerts.news')],
            default => ['ativo', 'on', $this->count($completed->count(), 'alerta', 'alertas').' · '.$count('news').' nos últimos 30 dias', route('alerts.news')],
        };

        $ready = $watches->filter->isCompleted();
        $threats = $this->threats($ready);
        $names = $ready->map(fn (LookalikeWatch $w) => $w->domain?->domain)->filter();
        $where = $names->count() === 1 ? $names->first() : $this->count($names->count(), 'domínio', 'domínios');
        $radar = match (true) {
            $watches->isEmpty() => ['não integrado', 'off', 'Domínios parecidos com o seu, registrados para golpe.', route('alerts.integrations')],
            $ready->isEmpty() => ['rascunho', 'warn', 'Entrevista no passo '.$watches->first()->step_reached.'.', route('alerts.lookalikes')],
            $ready->contains->isActive() => ['ativo', 'on', $where.' · '.$this->count($threats, 'ameaça aberta', 'ameaças abertas'), route('alerts.lookalikes')],
            default => ['pausado', 'off', $where, route('alerts.lookalikes')],
        };

        $github = match (true) {
            ! $monitor => ['não integrado', 'off', 'Chaves e código seus em repositórios públicos.', route('alerts.integrations')],
            ! $monitor->isCompleted() => ['rascunho', 'warn', 'Entrevista no passo '.$monitor->step_reached.'.', route('alerts.github')],
            $monitor->isPaused() => ['pausado', 'off', 'Aviso: '.$monitor->levelLabel(), route('alerts.github')],
            default => ['ativo', 'on', 'Aviso: '.$monitor->levelLabel().' · '.$count('github').' nos últimos 30 dias', route('alerts.github')],
        };

        return collect(['news' => $news, 'radar' => $radar, 'github' => $github])
            ->map(fn (array $s) => ['state' => $s[0], 'tone' => $s[1], 'line' => $s[2], 'url' => $s[3]])
            ->all();
    }

    private function count(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }
}
