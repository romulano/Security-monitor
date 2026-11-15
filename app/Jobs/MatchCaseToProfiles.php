<?php

namespace App\Jobs;

use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\NewsCase;
use App\Services\Alerts\AlertReviewer;
use App\Services\Alerts\AlertRouter;
use App\Services\Alerts\MatchEngine;
use App\Services\Alerts\ProfileCompiler;
use App\Services\Alerts\ProfileIndex;
use App\Services\Access\Destinations;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Um caso novo (ou atualizado com informação nova) contra os perfis ativos:
 * o percolator acha os candidatos, o MatchEngine decide, a IA dá o parecer
 * (ReviewAlertMatches) e o AlertRouter entrega. Roda na fila `alerts`, logo
 * depois da análise da IA.
 */
class MatchCaseToProfiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $caseId,
        public ?int $articleId,
        public string $kind = 'new_case',
        public bool $notify = true,
        public ?int $onlyProfileId = null,
    ) {
        $this->onQueue('alerts');
    }

    /** Chamado pelo CaseWriter: só enfileira se há alguém para avisar. */
    public static function dispatchFor(NewsCase $case, ?int $articleId, string $kind): void
    {
        if (! Settings::bool('alerts.enabled') || ! AlertProfile::query()->where('status', 'active')->exists()) {
            return;
        }

        static::dispatch($case->id, $articleId, $kind)->afterCommit();
    }

    public function handle(ProfileIndex $index, MatchEngine $engine, ProfileCompiler $compiler, AlertRouter $router, AlertReviewer $reviewer): void
    {
        if (! Settings::bool('alerts.enabled')) {
            return;
        }

        $case = NewsCase::query()->find($this->caseId);

        if (! $case || ! $case->is_published) {
            return;
        }

        $candidates = $this->onlyProfileId ? [$this->onlyProfileId] : $index->percolate($case);

        $profiles = AlertProfile::query()
            ->where('status', 'active')
            ->when($candidates !== null, fn ($q) => $q->whereIn('id', $candidates))
            ->with(['user.notificationChannels', 'techs', 'terms', 'domains'])
            ->get();

        $created = [];

        foreach ($profiles as $profile) {
            $result = $engine->evaluate($profile, $case, $compiler->terms($profile));

            if (! $result->matched()) {
                continue;
            }

            $previous = AlertMatch::query()
                ->where('profile_id', $profile->id)
                ->where('case_id', $case->id)
                ->relevant()
                ->pluck('criticity');

            // Atualização de caso já alertado: só se o cliente pediu ou se a
            // criticidade subiu.
            if ($previous->isNotEmpty() && ! $profile->notify_case_updates && ! $this->escalated($previous->all(), $case->criticity)) {
                continue;
            }

            $key = ['profile_id' => $profile->id, 'case_id' => $case->id, 'article_id' => $this->articleId];

            if (AlertMatch::query()->where($key)->exists()) {
                continue; // job repetido: esse artigo já foi avaliado para o perfil
            }

            try {
                // Savepoint: uma corrida no índice único não derruba o resto.
                $match = DB::transaction(fn () => AlertMatch::query()->create($key + [
                    'kind' => $previous->isNotEmpty() ? 'case_update' : 'new_case',
                    'criticity' => $case->criticity,
                    'score' => $result->score,
                    'reasons' => $result->reasons,
                    'suppressed_reason' => $result->suppressed,
                    'delivery_status' => $result->suppressed ? 'skipped' : 'pending',
                    // Histórico (sem notificar) fica na data da notícia, não na de agora.
                    'matched_at' => $this->notify ? now() : ($case->latest_at ?? now()),
                ]));
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            if (! $match->isRelevant()) {
                continue;
            }

            if ($this->notify) {
                $created[] = $match->setRelation('profile', $profile)->setRelation('case', $case);
            } else {
                $match->forceFill(['delivery_status' => 'skipped', 'delivery_note' => 'backfill'])->save();
            }
        }

        // Os alertas do mesmo destino para a mesma notícia saem juntos: os de um
        // cliente, ou os do mesmo setor (ou os sem setor) da empresa — nunca dois
        // setores numa mensagem (Destinations::key, E5b1). Antes, a IA dá o parecer
        // (ReviewAlertMatches) — menos quando a notícia cita a empresa: esse
        // sempre sai, e o nome do cliente não vai para o modelo.
        $destinations = app(Destinations::class);

        foreach (collect($created)->groupBy(fn (AlertMatch $m) => $destinations->key($m->profile)) as $group) {
            if ($reviewer->enabled() && ! $group->contains(fn (AlertMatch $m) => $m->hasBrandReason())) {
                $ids = $group->pluck('id')->all();
                AlertMatch::query()->whereIn('id', $ids)->update(['delivery_note' => 'ai_review']);
                ReviewAlertMatches::dispatch($ids);

                continue;
            }

            $router->routeGroup($group);
        }
    }

    private function escalated(array $previous, ?string $now): bool
    {
        $rank = fn (?string $level) => ($i = array_search($level ?? 'low', AlertProfile::LEVELS, true)) === false ? 3 : $i;
        $best = min(array_map($rank, $previous));

        return $rank($now) < $best;
    }
}
