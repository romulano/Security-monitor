<?php

namespace App\Services\Alerts;

use App\Models\AlertProfile;
use App\Services\Search\CaseIndex;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * "Com este perfil, nos últimos 30 dias você teria recebido N alertas."
 *
 * Busca com a query do percolator nos casos reais e passa cada candidato pelo
 * MatchEngine — o mesmo caminho de um alerta de verdade.
 */
class ProfilePreview
{
    public function __construct(
        private CaseIndex $cases,
        private ProfileCompiler $compiler,
        private MatchEngine $engine,
    ) {}

    /**
     * @return array{total: int, by_level: array<string, int>, examples: list<array>, per_week: float, error: ?string}
     */
    public function run(AlertProfile $profile, int $days = 30): array
    {
        $empty = ['total' => 0, 'by_level' => [], 'examples' => [], 'per_week' => 0.0, 'error' => null];
        $query = $this->compiler->matchQuery($profile);

        if (! $query) {
            return $empty;
        }

        try {
            $res = $this->cases->client()->search([
                'index' => $this->cases->index(),
                'body' => [
                    'size' => 500,
                    'query' => ['bool' => [
                        'must' => [ProfileIndex::queryFor($query)],
                        'filter' => [['range' => ['latest_at' => ['gte' => Carbon::now()->subDays($days)->toIso8601String()]]]],
                    ]],
                    'sort' => [['latest_at' => ['order' => 'desc']]],
                ],
            ])->asArray();
        } catch (Throwable) {
            return ['error' => 'Prévia indisponível: o serviço de busca não respondeu.'] + $empty;
        }

        $terms = $this->compiler->terms($profile);
        $alerts = [];

        foreach (Arr::get($res, 'hits.hits', []) as $hit) {
            $doc = $hit['_source'];
            $result = $this->engine->evaluate($profile, $doc, $terms);

            if ($result->matched() && $result->suppressed === null) {
                $alerts[] = [
                    'uuid' => $doc['uuid'] ?? null,
                    'title' => $profile->language === 'pt' ? ($doc['title_pt'] ?? null) ?: ($doc['title'] ?? '') : ($doc['title'] ?? ''),
                    'criticity' => $doc['criticity'] ?? null,
                    'latest_at' => $doc['latest_at'] ?? null,
                    'why' => $result->summary(),
                    'brand' => $result->isBrand(),
                ];
            }
        }

        $byLevel = [];

        foreach (AlertProfile::LEVELS as $level) {
            $count = count(array_filter($alerts, fn ($a) => ($a['criticity'] ?? 'low') === $level));

            if ($count) {
                $byLevel[$level] = $count;
            }
        }

        return [
            'total' => count($alerts),
            'by_level' => $byLevel,
            'examples' => array_slice($alerts, 0, 3),
            'per_week' => round(count($alerts) / ($days / 7), 1),
            'error' => null,
        ];
    }
}
