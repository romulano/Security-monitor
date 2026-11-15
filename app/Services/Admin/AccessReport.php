<?php

namespace App\Services\Admin;

use App\Models\CaseMerge;
use App\Models\NewsCase;
use App\Models\PageView;
use App\Services\Monitoring\OwnAddresses;
use App\Services\Monitoring\PageViewRecorder;
use App\Support\AttackSignatures;
use App\Support\RobotCatalog;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin → Acessos: as páginas abertas no período, somadas por quem abriu (IP),
 * por página, por país e por dono e tipo de robô, e a lista das últimas, com
 * os filtros da tela. Uma aba por método, cada uma só com as suas consultas.
 */
class AccessReport
{
    /** Períodos da tela, em horas. */
    public const PERIODS = ['24h' => 24, '7d' => 168, '30d' => 720];

    /** O dono do robô que vem de um IP nosso (OwnAddresses). */
    public const OURS = 'Security Monitor (nosso)';

    /** O caminho de uma matéria, com o uuid dela no 1º grupo. */
    private const STORY = '^/materia/([0-9a-fA-F-]{36})';

    /** O tempo mínimo (em ms, o que o beacon do navegador manda) numa página, no período, para o IP contar como pessoa. */
    public const HUMAN_MIN_MS = 1000;

    /** Respostas 404 no período a partir das quais o IP está varrendo endereços (/contact, /imprint, /wp-admin...), mesmo com o tempo. */
    public const NOT_FOUND = 5;

    public function __construct(private PageViewRecorder $views, private OwnAddresses $own) {}

    /**
     * Visão geral: os números, as páginas, quem mais acessou (com o tipo de visitante), as barras no tempo, os
     * países e os últimos acessos (25 por página). Os redirecionamentos (o /idioma, 3xx) não contam como página aberta.
     *
     * @param  array<string, ?string>  $filters
     * @return array<string, mixed>
     */
    public function overview(array $filters): array
    {
        return $this->run($filters, function (object $c): array {
            $page = 'status not between 300 and 399';

            return [
                'totals' => $c->scope()->toBase()->selectRaw(
                    "count(*) filter (where {$page}) as views, count(*) filter (where status between 300 and 399) as redirects,"
                    .' count(distinct ip) as ips, count(distinct country) as countries, count(distinct user_id) as accounts,'
                    // Robô é tudo o que não é pessoa (person). Os cartões contam páginas abertas, como "Páginas abertas"
                    // (sem os 3xx): people_views + bots = views. Os IPs de cada lado vão na nota; people é o de pessoas.
                    ." count(*) filter (where {$page} and {$c->person}) as people_views, count(distinct ip) filter (where {$c->person}) as people,"
                    ." count(*) filter (where {$page} and not {$c->person}) as bots, count(distinct ip) filter (where {$page} and not {$c->person}) as bot_ips,"
                    ." count(*) filter (where {$page} and is_bot) as declared,"
                    .' count(*) filter (where status >= 400) as errors, avg(duration_ms) as avg_ms',
                    [...$c->personBindings, ...$c->personBindings, ...$c->personBindings, ...$c->personBindings],
                )
                    ->selectSub($c->scope(['nossos' => $c->filters['nossos'] === 'esconder' ? 'so' : 'nenhum'])->toBase()->selectRaw('count(*)'), 'hidden_ours')
                    ->first(),
                'series' => $this->series($c, $page),
                'byIp' => $c->scope()->toBase()
                    ->selectRaw(
                        'ip, count(*) as views, count(distinct path) as pages, sum(duration_ms) as total_ms,'
                        .' min(created_at) as first_at, max(created_at) as last_at, bool_or(is_bot) as bot,'
                        ." {$c->likely} as likely_bot, {$c->ours['sql']} as ours, {$c->attacker} as attacker,"
                        .' (array_agg(country order by created_at desc))[1] as country,'
                        .' (array_agg(user_agent order by created_at desc))[1] as user_agent,'
                        .' (array_agg((select email from users where users.id = page_views.user_id) order by created_at desc) filter (where user_id is not null))[1] as email,'
                        .' (array_agg('.$c->robot['kind'].' order by created_at desc) filter (where is_bot))[1] as robot_kind,'
                        .' (array_agg('.$c->robot['owner'].' order by created_at desc) filter (where is_bot))[1] as robot_owner',
                        [...$c->likelyBindings, ...$c->ours['bindings'], ...$c->attackerBindings, ...$c->robot['bindings'], ...$c->robot['bindings']]
                    )
                    ->groupBy('ip')->orderByDesc('views')->orderByDesc('last_at')->limit(50)->get(),
                'byPage' => $c->scope()->toBase()->whereRaw($page)
                    ->selectRaw("split_part(path, '?', 1) as page, count(*) as views, count(distinct ip) as ips, avg(duration_ms) as avg_ms")
                    ->groupByRaw("split_part(path, '?', 1)")->orderByDesc('views')->limit(30)->get(),
                'byCountry' => $c->scope()->toBase()
                    ->selectRaw("coalesce(country, '—') as country, count(*) as views, count(distinct ip) as ips")
                    ->groupBy('country')->orderByDesc('views')->limit(20)->get(),
                'recent' => $c->scope()->select('page_views.*')
                    ->selectRaw(
                        "{$c->likely} as likely_bot, {$c->ours['sql']} as ours, {$c->attacker} as attacker, {$c->robot['kind']} as robot_kind,"
                        ." {$c->robot['owner']} as robot_owner, (select email from users where users.id = page_views.user_id) as email",
                        [...$c->likelyBindings, ...$c->ours['bindings'], ...$c->attackerBindings, ...$c->robot['bindings'], ...$c->robot['bindings']]
                    )
                    ->latest('created_at')->latest('id')->paginate(25)->withQueryString()
                    // A cara de ataque (AttackSignatures), em PHP e só sobre as linhas da página.
                    ->through(fn (PageView $view) => $view->setAttribute('attack', AttackSignatures::classify($view->path, $view->user_agent, $view->referer))),
            ];
        });
    }

    /**
     * Robôs: o quadro por dono e tipo e as matérias que as IAs abriram a pedido de alguém.
     *
     * @param  array<string, ?string>  $filters
     * @return array<string, mixed>
     */
    public function robots(array $filters): array
    {
        // A aba já é só de robô (is_bot): "Só pessoas" zeraria e "Só robôs" daria o mesmo que "Todos".
        return $this->run(['robos' => 'com'] + $filters, fn (object $c): array => [
            // Robôs por dono e tipo: quantos acessos, de quantos IPs, quantas páginas e quantas matérias.
            'byRobot' => $c->scope()->toBase()
                ->where('is_bot', true)
                ->selectRaw(
                    $c->robot['owner'].' as owner, '.$c->robot['kind'].' as kind, count(*) as views, count(distinct ip) as ips,'
                    ." count(distinct split_part(path, '?', 1)) as pages, count(distinct substring(path from '".self::STORY."')) as stories,"
                    .' max(created_at) as last_at',
                    [...$c->robot['bindings'], ...$c->robot['bindings']]
                )
                ->groupByRaw('1, 2')->orderByDesc('views')->orderByRaw('1')->limit(30)->get(),
            'aiAsked' => $this->aiAsked($c->scope(), $c->robot),
        ]);
    }

    /**
     * Origens, só de pessoas (person: sem robô, provável robô, atacante nem nosso) e só respostas abaixo de 400: os sites
     * de onde vieram, a página de entrada (a 1ª do IP no dia) e as que levaram ao cadastro.
     *
     * @param  array<string, ?string>  $filters
     * @return array<string, mixed>
     */
    public function origins(array $filters): array
    {
        return $this->run($filters, function (object $c): array {
            // Tipo e dono só existem no robô declarado (zerariam a aba) e a página mudaria o que é a entrada: ficam de fora.
            $people = fn (): Builder => $c->scope(['robos' => 'sem', 'nossos' => 'esconder', 'tipo' => '', 'dono' => '', 'pagina' => ''])->where('status', '<', 400);
            $domain = "substring(referer from '^https?://(?:www\\.)?([^/:?#]+)')";
            $own = array_values(array_unique(array_filter([(string) parse_url((string) config('app.url'), PHP_URL_HOST), request()->getHost()])));

            return [
                'referrers' => $people()->toBase()->whereNotNull('referer')
                    ->whereRaw("lower({$domain}) <> all(cast(? as text[]))", ['{'.implode(',', $own).'}'])
                    ->selectRaw("lower({$domain}) as domain, count(*) as views, count(distinct ip) as ips")
                    ->groupByRaw('1')->havingRaw("lower({$domain}) is not null")->orderByDesc('views')->limit(20)->get(),
                'entries' => DB::query()->fromSub(
                    $people()->toBase()->selectRaw("distinct on (ip, (created_at at time zone 'America/Sao_Paulo')::date) split_part(path, '?', 1) as page")
                        ->orderByRaw("ip, (created_at at time zone 'America/Sao_Paulo')::date, created_at"),
                    'e',
                )->selectRaw('page, count(*) as visits')->groupBy('page')->orderByDesc('visits')->limit(20)->get(),
                // De onde se chega ao cadastro: a página (do site ou de fora) que o navegador diz ter aberto antes.
                'toSignup' => $people()->toBase()->where('path', 'like', '/cadastro%')
                    ->selectRaw("coalesce(split_part(referer, '?', 1), '(direto)') as page, count(*) as views, count(distinct ip) as ips")
                    ->groupByRaw('1')->orderByDesc('views')->limit(20)->get(),
            ];
        });
    }

    /**
     * Roda as consultas da aba numa transação com teto de 5 s por consulta, com os filtros e a chave da monitoria,
     * que todas as abas mostram.
     *
     * @param  array<string, ?string>  $filters
     * @return array<string, mixed>
     */
    private function run(array $filters, Closure $tab): array
    {
        return DB::transaction(function () use ($filters, $tab): array {
            DB::statement("set local statement_timeout = '5s'");
            $c = $this->context($filters);

            return $tab($c) + [
                'enabled' => $this->views->enabled(),
                'filters' => $c->filters,
            ];
        });
    }

    /**
     * Os filtros, o período e os trechos de SQL que as abas usam: a pessoa (person), o provável robô (o IP que não
     * passou no teste de pessoa, humans) e o atacante (o IP com evento de carga de ataque no período), como
     * subconsultas, nunca como lista de IPs.
     *
     * @param  array<string, ?string>  $filters
     */
    private function context(array $filters): object
    {
        $filters = [
            'periodo' => $filters['periodo'] ?? '24h',
            'robos' => $filters['robos'] ?? 'com',
            // Os nossos (OwnAddresses) ficam de fora por padrão: depois de zerar a tabela, 13 de 17 eram nossos.
            'nossos' => $filters['nossos'] ?? 'esconder',
            'ip' => trim((string) ($filters['ip'] ?? '')),
            'pais' => strtoupper(trim((string) ($filters['pais'] ?? ''))),
            'pagina' => trim((string) ($filters['pagina'] ?? '')),
            // O tipo (RobotCatalog::KINDS) e o dono do robô declarado.
            'tipo' => trim((string) ($filters['tipo'] ?? '')),
            'dono' => trim((string) ($filters['dono'] ?? '')),
        ];
        // O robô nosso só existe com os nossos à mostra: pedir por ele com o padrão (esconder) mostra os nossos.
        if ($filters['nossos'] === 'esconder' && ($filters['tipo'] === 'ours' || $filters['dono'] === self::OURS)) {
            $filters['nossos'] = 'mostrar';
        }

        $since = now()->subHours(self::PERIODS[$filters['periodo']] ?? 24);
        $humans = $this->humans($since);
        $attackers = DB::table('access_events')->select('ip')->where('created_at', '>=', $since)->whereIn('category', AttackSignatures::PAYLOADS);
        $ours = $this->ours();
        $robot = $this->robot($ours);
        $person = $this->person($humans, $attackers, $ours);

        return new class($filters, $since, $humans, $attackers, $ours, $robot, $person, fn (array $f) => $this->scope($f, $since, $person, $ours, $robot))
        {
            /** O IP que não passou no teste de pessoa (humans): o selo "provável robô", quando não é nada mais forte. */
            public string $likely;

            public string $attacker;

            /** O acesso de pessoa, a única definição da tela: ver person(). */
            public string $person;

            /** @var list<mixed> */
            public array $likelyBindings;

            /** @var list<mixed> */
            public array $attackerBindings;

            /** @var list<mixed> */
            public array $personBindings;

            public function __construct(public array $filters, public Carbon $since, QueryBuilder $humans, QueryBuilder $attackers, public array $ours, public array $robot, array $person, private Closure $scoped)
            {
                $this->likely = 'ip not in ('.$humans->toSql().')';
                $this->attacker = 'ip in ('.$attackers->toSql().')';
                $this->person = $person['sql'];
                $this->likelyBindings = $humans->getBindings();
                $this->attackerBindings = $attackers->getBindings();
                $this->personBindings = $person['bindings'];
            }

            public function scope(array $change = []): Builder
            {
                return ($this->scoped)($change + $this->filters);
            }
        };
    }

    /**
     * As barras: por hora nas últimas 24 h, por dia nos outros períodos, no horário de Brasília, com pessoas e
     * robôs (tudo o que não é pessoa) empilhados. A hora ou o dia sem acesso entra zerado.
     *
     * @return Collection<int, object{label: string, people: int, robots: int}>
     */
    private function series(object $c, string $page): Collection
    {
        $hourly = $c->filters['periodo'] === '24h';
        $unit = $hourly ? 'hour' : 'day';
        $rows = $c->scope()->toBase()->whereRaw($page)
            ->selectRaw(
                "to_char(date_trunc('{$unit}', created_at at time zone 'America/Sao_Paulo'), 'YYYY-MM-DD HH24') as bucket,"
                ." count(*) filter (where {$c->person}) as people, count(*) filter (where not {$c->person}) as robots",
                [...$c->personBindings, ...$c->personBindings],
            )
            ->groupByRaw('1')->get()->keyBy('bucket');

        $series = collect();
        $at = $c->since->copy()->timezone('America/Sao_Paulo')->startOf($unit);

        for ($end = now()->timezone('America/Sao_Paulo'); $at <= $end; $at = $at->copy()->add(1, $unit)) {
            $row = $rows[$at->format('Y-m-d H')] ?? null;
            $series->push((object) [
                'label' => $hourly ? $at->format('G').'h' : $at->format('d/m'),
                'people' => (int) ($row->people ?? 0),
                'robots' => (int) ($row->robots ?? 0),
            ]);
        }

        return $series;
    }

    /** @return array{sql: string, bindings: list<string>} */
    private function ours(): array
    {
        return $this->own->sql();
    }

    /**
     * O tipo e o dono do robô, como a tela mostra (nulos para gente). O nosso
     * é pelo IP (qualquer um escreve SecurityMonitorBot no navegador); os outros, pelo
     * que declaram (bot_kind e bot_owner, gravados com o acesso). Cada trecho
     * leva os `bindings` uma vez.
     *
     * @param  array{sql: string, bindings: list<string>}  $ours
     * @return array{kind: string, owner: string, bindings: list<string>}
     */
    private function robot(array $ours): array
    {
        return [
            'kind' => "(case when is_bot then (case when {$ours['sql']} then 'ours' else coalesce(bot_kind, '".RobotCatalog::UNKNOWN."') end) end)",
            'owner' => "(case when is_bot then (case when {$ours['sql']} then '".self::OURS."' else bot_owner end) end)",
            'bindings' => $ours['bindings'],
        ];
    }

    /**
     * As matérias que as IAs abriram a pedido de alguém (ChatGPT-User,
     * Claude-User...): mostram o que as pessoas perguntam às IAs. O título da
     * que a fusão juntou depois sai de case_merges.
     *
     * @param  array{kind: string, owner: string, bindings: list<string>}  $robot
     * @return Collection<int, object>
     */
    private function aiAsked(Builder $scope, array $robot): Collection
    {
        $rows = $scope->toBase()
            ->whereRaw($robot['kind']." = 'ai_user'", $robot['bindings'])
            ->whereRaw("path ~ '".self::STORY."'")
            ->selectRaw(
                "lower(substring(path from '".self::STORY."')) as uuid, count(*) as views, string_agg(distinct ".$robot['owner'].", ', ') as owners,"
                .' max(created_at) as last_at',
                $robot['bindings']
            )
            ->groupByRaw('1')->orderByDesc('views')->orderByDesc('last_at')->limit(20)->get();

        $uuids = $rows->pluck('uuid')->filter(fn ($uuid) => Str::isUuid($uuid))->values()->all();
        $titles = $uuids === [] ? collect() : NewsCase::query()->whereIn('uuid', $uuids)->pluck('title', 'uuid')
            ->union(CaseMerge::query()->whereIn('uuid', $uuids)->pluck('title', 'uuid'));

        return $rows->each(fn (object $row) => $row->title = $titles[$row->uuid] ?? null);
    }

    /**
     * Os IPs que, no período, contam como gente: ficaram HUMAN_MIN_MS ou mais em alguma página (o tempo sai do
     * JavaScript, que o robô quase nunca roda, e o navegador sem cabeça que roda fecha a página antes) num acesso não
     * declarado como robô, e não receberam NOT_FOUND respostas 404 (quem varre /contact, /imprint, /team atrás do que
     * existe, mesmo rodando o JavaScript). É por IP no período, não por linha: a página aberta agora, cujo tempo
     * ainda não chegou, é de pessoa se o IP já for. O resto é provável robô; os critérios antigos (só buscas com
     * várias facetas, páginas em sequência rápida) exigiam nunca mandar o tempo e cabem todos aqui. Sai do que cada
     * acesso já tem: nada novo é gravado sobre ninguém.
     */
    private function humans(Carbon $since): QueryBuilder
    {
        return DB::table('page_views')->where('created_at', '>=', $since)
            ->select('ip')
            ->groupBy('ip')
            ->havingRaw('bool_or(not is_bot and duration_ms >= ?) and count(*) filter (where status = 404) < ?', [self::HUMAN_MIN_MS, self::NOT_FOUND]);
    }

    /**
     * O acesso de pessoa, a única definição da tela (barras, cartões, o filtro Robôs, o selo e a aba Origens): não
     * declarado como robô, de um IP que é gente no período (humans), que não é atacante nem nosso. Robô é o resto.
     *
     * @param  array{sql: string, bindings: list<string>}  $ours
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function person(QueryBuilder $humans, QueryBuilder $attackers, array $ours): array
    {
        return [
            'sql' => "(not is_bot and ip in ({$humans->toSql()}) and ip not in ({$attackers->toSql()}) and not {$ours['sql']})",
            'bindings' => [...$humans->getBindings(), ...$attackers->getBindings(), ...$ours['bindings']],
        ];
    }

    /**
     * @param  array{periodo: string, robos: string, tipo: string, dono: string, nossos: string, ip: string, pais: string, pagina: string}  $filters
     * @param  array{sql: string, bindings: list<mixed>}  $person
     * @param  array{sql: string, bindings: list<string>}  $ours
     * @param  array{kind: string, owner: string, bindings: list<string>}  $robot
     */
    private function scope(array $filters, Carbon $since, array $person, array $ours, array $robot): Builder
    {
        return PageView::query()
            ->where('created_at', '>=', $since)
            // "Só pessoas" e "Só robôs" pela mesma definição (person): um é o complemento do outro.
            ->when($filters['robos'] === 'sem', fn (Builder $q) => $q->whereRaw($person['sql'], $person['bindings']))
            ->when($filters['robos'] === 'so', fn (Builder $q) => $q->whereRaw('not '.$person['sql'], $person['bindings']))
            ->when($filters['tipo'] !== '', fn (Builder $q) => $q->whereRaw($robot['kind'].' = ?', [...$robot['bindings'], $filters['tipo']]))
            ->when($filters['dono'] !== '', fn (Builder $q) => $q->whereRaw($robot['owner'].' = ?', [...$robot['bindings'], $filters['dono']]))
            ->when($filters['nossos'] === 'esconder', fn (Builder $q) => $q->whereRaw('not '.$ours['sql'], $ours['bindings']))
            ->when($filters['nossos'] === 'so', fn (Builder $q) => $q->whereRaw($ours['sql'], $ours['bindings']))
            // Para a contagem dos nossos escondidos quando eles não estão escondidos: nenhum.
            ->when($filters['nossos'] === 'nenhum', fn (Builder $q) => $q->whereRaw('false'))
            ->when($filters['ip'] !== '', fn (Builder $q) => $q->where('ip', $filters['ip']))
            ->when($filters['pais'] !== '', fn (Builder $q) => $q->where('country', $filters['pais']))
            // A página exata, sem a query; com * no fim, o prefixo (/materia/*).
            ->when(str_ends_with($filters['pagina'], '*'), fn (Builder $q) => $q->where('path', 'like', $this->like(substr($filters['pagina'], 0, -1)).'%'))
            ->when($filters['pagina'] !== '' && ! str_ends_with($filters['pagina'], '*'), fn (Builder $q) => $q->whereRaw("split_part(path, '?', 1) = ?", [$filters['pagina']]));
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
