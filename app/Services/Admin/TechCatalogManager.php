<?php

namespace App\Services\Admin;

use App\Models\AlertMatch;
use App\Models\NewsCase;
use App\Models\TechCatalog;
use App\Services\Alerts\InterviewService;
use App\Services\Alerts\TermMatcher;

/**
 * Admin → Alertas → Catálogo. O catálogo é o dicionário entre o que o cliente
 * marca e o que se procura nos casos; editar uma tecnologia recompila os
 * perfis que a usam.
 */
class TechCatalogManager
{
    public function __construct(private InterviewService $interview) {}

    /**
     * @param  array{q?: ?string, categoria?: ?string, origem?: ?string}  $filters
     * @param  ?int  $editingId  a tecnologia aberta no formulário de edição
     * @param  bool  $creating  formulário de nova tecnologia aberto
     */
    public function page(array $filters, ?int $editingId, bool $creating): array
    {
        $techs = TechCatalog::query()
            ->with('parent:id,name')
            ->withCount('profiles')
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('slug', 'ilike', "%{$s}%")))
            ->when($filters['categoria'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($filters['origem'] ?? null, fn ($q, $o) => $q->where('origin', $o))
            ->orderBy('category')->orderBy('position')
            ->paginate(40)
            ->withQueryString();

        $editing = $editingId ? TechCatalog::query()->find($editingId) : null;

        return [
            'techs' => $techs,
            'usage' => $this->usage($techs->pluck('name')->all()),
            'filters' => $filters,
            'editing' => $editing,
            'creating' => $creating,
            'reach' => $editing ? $this->reach($editing) : null,
            'autoCount' => TechCatalog::query()->where('origin', 'discovery')->count(),
            'parents' => TechCatalog::query()->whereNull('parent_id')->whereIn('category', ['language', 'os'])->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** A tecnologia nova vai para o fim da sua categoria. */
    public function create(array $fields): TechCatalog
    {
        return TechCatalog::query()->create($fields + [
            'position' => (int) TechCatalog::query()->where('category', $fields['category'])->max('position') + 1,
        ]);
    }

    /** Grava e recompila os perfis que usam a tecnologia; devolve quantos. */
    public function update(TechCatalog $tech, array $fields): int
    {
        $tech->update($fields);

        return $this->recompileUsers($tech);
    }

    /** Ativa ou desativa e recompila os perfis que usam a tecnologia; devolve quantos. */
    public function toggle(TechCatalog $tech): int
    {
        $tech->update(['active' => ! $tech->active]);

        return $this->recompileUsers($tech);
    }

    private function recompileUsers(TechCatalog $tech): int
    {
        $count = 0;

        $tech->profiles()->whereNotNull('completed_at')->get()->each(function ($profile) use (&$count) {
            $this->interview->recompile($profile);
            $count++;
        });

        return $count;
    }

    /** Alertas dos últimos 30 dias e taxa de "não relevante" por tecnologia (pelo rótulo do motivo). */
    private function usage(array $names): array
    {
        $usage = [];

        AlertMatch::query()->relevant()->where('matched_at', '>=', now()->subDays(30))
            ->select(['reasons', 'feedback'])->limit(5000)->get()
            ->each(function (AlertMatch $match) use ($names, &$usage) {
                foreach (collect($match->reasons)->pluck('label')->unique()->intersect($names) as $name) {
                    $usage[$name]['alerts'] = ($usage[$name]['alerts'] ?? 0) + 1;
                    $usage[$name]['irrelevant'] = ($usage[$name]['irrelevant'] ?? 0) + ($match->feedback === 'irrelevant' ? 1 : 0);
                }
            });

        return $usage;
    }

    /** Quantos casos dos últimos 90 dias estes termos alcançam, pela mesma regra do alerta. */
    private function reach(TechCatalog $tech): int
    {
        $count = 0;
        $fields = ['affected_products', 'vendors'];

        NewsCase::query()->where('latest_at', '>=', now()->subDays(90))
            ->select(['id', 'title', 'title_pt', 'summary', 'summary_pt', 'indicators'])
            ->limit(5000)->get()
            ->each(function (NewsCase $case) use ($tech, $fields, &$count) {
                $products = array_merge(...array_map(fn ($f) => $case->indicatorValues($f), $fields));
                $haystack = $tech->strict ? implode(' | ', $products) : implode("\n", array_merge($products, [$case->title, $case->title_pt, $case->summary, $case->summary_pt]));

                foreach ($tech->terms ?? [] as $term) {
                    if (TermMatcher::contains($haystack, $term)) {
                        $count++;

                        return;
                    }
                }
            });

        return $count;
    }
}
