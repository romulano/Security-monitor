<?php

namespace App\Services\Admin;

use App\Models\AccessEvent;
use App\Services\Monitoring\OwnAddresses;
use App\Support\AttackSignatures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Acessos → Ataques: o que o site recusou ou que teve cara de ataque
 * (access_events), por categoria, por método e resposta, por IP (com quem varre
 * endereços), as tentativas contra contas, o que passou do teto por minuto e a
 * lista dos eventos. Os nossos ficam escondidos por padrão e nunca são
 * atacantes. Sete consultas, numa transação com teto de 5 s.
 */
class AttackReport
{
    /** Caminhos distintos com 4xx no período a partir dos quais o IP está varrendo o site. */
    public const SWEEP = 10;

    public function __construct(private OwnAddresses $own) {}

    /**
     * A categoria "recusado" é o evento sem categoria (só a resposta 4xx/5xx); varredura=1 deixa só os IPs que
     * varreram (SWEEP endereços ou mais com 4xx).
     *
     * @param  array{periodo?: ?string, categoria?: ?string, ip?: ?string, pais?: ?string, nossos?: ?string, varredura?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function overview(array $filters): array
    {
        $filters = [
            'periodo' => $filters['periodo'] ?? '24h',
            'categoria' => (string) ($filters['categoria'] ?? ''),
            'ip' => trim((string) ($filters['ip'] ?? '')),
            'pais' => strtoupper(trim((string) ($filters['pais'] ?? ''))),
            'nossos' => $filters['nossos'] ?? 'esconder',
            'varredura' => (string) ($filters['varredura'] ?? ''),
        ];
        $ours = $this->own->sql();
        $since = now()->subHours(AccessReport::PERIODS[$filters['periodo']] ?? 24);
        $scope = fn (): Builder => AccessEvent::query()
            ->where('created_at', '>=', $since)
            ->when($filters['nossos'] === 'esconder', fn (Builder $q) => $q->whereRaw('not '.$ours['sql'], $ours['bindings']))
            ->when($filters['nossos'] === 'so', fn (Builder $q) => $q->whereRaw($ours['sql'], $ours['bindings']))
            ->when($filters['categoria'] === 'recusado', fn (Builder $q) => $q->whereNull('category'))
            ->when(! in_array($filters['categoria'], ['', 'recusado'], true), fn (Builder $q) => $q->where('category', $filters['categoria']))
            ->when($filters['ip'] !== '', fn (Builder $q) => $q->where('ip', $filters['ip']))
            ->when($filters['pais'] !== '', fn (Builder $q) => $q->where('country', $filters['pais']));
        $payload = "category in ('".implode("', '", AttackSignatures::PAYLOADS)."')";

        return DB::transaction(function () use ($filters, $scope, $ours, $since, $payload): array {
            DB::statement("set local statement_timeout = '5s'");

            return [
                'filters' => $filters,
                'byCategory' => $scope()->toBase()->selectRaw('category, count(*) as events, count(distinct ip) as ips')
                    ->groupBy('category')->orderByDesc('events')->get(),
                'byStatus' => $scope()->toBase()->selectRaw("method, coalesce((status / 100)::text || 'xx', '—') as band, count(*) as events")
                    ->groupByRaw('1, 2')->orderByDesc('events')->limit(20)->get(),
                // Os 50 IPs com mais eventos: o que varreram, quantas categorias, as recusas de login e contra quantas contas.
                // Com varredura=1, só os que pediram SWEEP endereços ou mais com 4xx (o selo "varredura").
                'byIp' => $scope()->toBase()->selectRaw(
                    'ip, count(*) as events, count(distinct path) filter (where status between 400 and 499) as paths,'
                    .' count(distinct category) as categories, count(*) filter (where auth is not null) as refusals,'
                    .' count(distinct account_hmac) as accounts, min(created_at) as first_at, max(created_at) as last_at,'
                    .' (array_agg(country order by created_at desc))[1] as country,'
                    .' (array_agg(user_agent order by created_at desc))[1] as user_agent,'
                    ." {$ours['sql']} as ours, bool_or(bot_kind = 'scanner') as scanner, bool_or({$payload}) as attacker,"
                    .' (array_agg(bot_kind order by created_at desc) filter (where bot_kind is not null))[1] as robot_kind',
                    $ours['bindings'],
                )->groupBy('ip')
                    ->when($filters['varredura'] === '1', fn ($q) => $q->havingRaw('count(distinct path) filter (where status between 400 and 499) >= ?', [self::SWEEP]))
                    ->orderByDesc('events')->limit(50)->get()
                    // O nosso nunca é atacante.
                    ->each(fn (object $row) => $row->attacker = $row->attacker && ! $row->ours),
                // A mesma conta (o código do e-mail) tentada de 2 IPs ou mais: ataque dirigido.
                'accounts' => $scope()->toBase()->whereNotNull('account_hmac')
                    ->selectRaw('account_hmac, count(distinct ip) as ips, count(*) as refusals, max(created_at) as last_at')
                    ->groupBy('account_hmac')->havingRaw('count(distinct ip) >= 2')->orderByDesc('ips')->limit(20)->get(),
                'dropped' => DB::table('access_event_overflow')->where('minute', '>=', $since)
                    ->selectRaw("to_char(date_trunc('hour', minute at time zone 'America/Sao_Paulo'), 'DD/MM HH24\"h\"') as hour, sum(dropped) as dropped")
                    ->groupByRaw('date_trunc(\'hour\', minute at time zone \'America/Sao_Paulo\')')->orderByRaw('date_trunc(\'hour\', minute at time zone \'America/Sao_Paulo\') desc')
                    ->limit(48)->get(),
                'events' => $scope()->latest('created_at')->latest('id')->paginate(25)->withQueryString(),
            ];
        });
    }
}
