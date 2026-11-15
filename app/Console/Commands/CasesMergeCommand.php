<?php

namespace App\Console\Commands;

use App\Jobs\MergeSimilarCases;
use App\Models\NewsCase;
use App\Services\Alerts\Rematcher;
use App\Services\Merge\CaseMerger;
use App\Services\Merge\MergeEditor;
use App\Services\Merge\SimilarCases;
use App\Support\Prompts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Fusão de matérias: acha as matérias novas que contam a mesma história de outra
 * já publicada e manda cada grupo para a IA confirmar e escrever a matéria
 * nova. Roda duas vezes ao dia, às 00:00 e às 12:00 de Brasília.
 */
class CasesMergeCommand extends Command
{
    protected $signature = 'secmon:cases-merge
        {--since= : matérias novas desde (26h, 3d, 90m); o padrão é secmon.merge.focus_hours}
        {--case=* : só estas matérias (uuid), levadas juntas à IA}
        {--candidates : só lista os grupos parecidos, sem chamar a IA}
        {--dry-run : mostra o que a IA juntaria, sem escrever nem fundir}
        {--sync : funde aqui mesmo, sem fila}';

    protected $description = 'Junta numa matéria nova as matérias que contam a mesma história';

    public function handle(SimilarCases $similar, MergeEditor $editor, CaseMerger $merger): int
    {
        if (! $this->option('candidates') && ! $editor->enabled()) {
            $this->warn('Fusão desligada (CASES_MERGE) ou hermes sem endereço: nada é juntado.');

            return self::SUCCESS;
        }

        try {
            $groups = Prompts::stageOn('fusao-agrupar') ? $this->groups($similar) : [];
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        if (! Prompts::stageOn('fusao-agrupar')) {
            $this->warn('"Agrupar" está desligada em Admin › Prompts: nenhuma matéria é juntada.');
        }

        $inGroups = array_merge(...array_column($groups, 'ids') ?: [[]]);
        $rewrites = $this->option('case') ? [] : array_values(array_diff($merger->outdated(), $inGroups));

        $this->report($groups, $rewrites);

        if ($this->option('candidates')) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($groups, $editor);
        }

        foreach ([...array_column($groups, 'ids'), ...array_map(fn ($id) => [$id], $rewrites)] as $ids) {
            $this->option('sync') ? MergeSimilarCases::dispatchSync($ids) : MergeSimilarCases::dispatch($ids);
        }

        $this->info(sprintf(
            '%d grupo(s) e %d matéria(s) consolidada(s) para atualizar %s.',
            count($groups),
            count($rewrites),
            $this->option('sync') ? 'processados' : 'na fila merge',
        ));

        return self::SUCCESS;
    }

    /** @return list<array{ids: list<int>, score: ?float}> */
    private function groups(SimilarCases $similar): array
    {
        if ($uuids = $this->option('case')) {
            $ids = NewsCase::query()->whereIn('uuid', array_filter($uuids, Str::isUuid(...)))->where('is_published', true)->orderBy('id')->pluck('id')->all();

            if (count($ids) < 2) {
                throw new InvalidArgumentException('Informe ao menos duas matérias publicadas com --case=<uuid>.');
            }

            return [['ids' => $ids, 'score' => null]];
        }

        $since = $this->option('since') ? Rematcher::since((string) $this->option('since')) : null;

        // O grupo que a IA recusou inteiro só volta se mudar (outra matéria parecida entrou nele).
        return array_values(array_filter(
            $similar->groups($since),
            fn (array $group) => ! Cache::has(MergeSimilarCases::rejectedKey($group['ids'])),
        ));
    }

    private function report(array $groups, array $rewrites): void
    {
        $titles = NewsCase::query()
            ->whereIn('id', array_merge($rewrites, ...array_column($groups, 'ids') ?: [[]]))
            ->pluck('title', 'id');

        foreach ($groups as $group) {
            $this->line(sprintf('<comment>%s · %d matérias</comment>', $group['score'] === null ? 'manual' : 'nota '.$group['score'], count($group['ids'])));

            foreach ($group['ids'] as $id) {
                $this->line('  #'.$id.' '.mb_strimwidth((string) ($titles[$id] ?? '?'), 0, 130, '…'));
            }
        }

        foreach ($rewrites as $id) {
            $this->line('<comment>atualizar</comment> #'.$id.' '.mb_strimwidth((string) ($titles[$id] ?? '?'), 0, 130, '…'));
        }

        if ($groups === [] && $rewrites === []) {
            $this->line('Nenhuma matéria parecida.');
        }
    }

    private function dryRun(array $groups, MergeEditor $editor): int
    {
        $this->newLine();
        $this->info('Simulação: a IA confirma os grupos, nada é escrito.');

        foreach ($groups as $group) {
            $cases = NewsCase::query()->whereIn('id', $group['ids'])->orderBy('id')->get();

            try {
                $confirmed = $editor->confirm($cases);
            } catch (Throwable $e) {
                $this->error('['.implode(', ', $group['ids']).'] → falhou: '.$e->getMessage());

                continue;
            }

            $this->line('['.implode(', ', $group['ids']).'] → '.($confirmed === []
                ? 'separadas'
                : implode(' · ', array_map(fn ($g) => 'juntar ['.implode(', ', $g['ids']).']: '.$g['reason'], $confirmed))));
        }

        return self::SUCCESS;
    }
}
