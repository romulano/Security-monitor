<?php

namespace App\Models;

use App\Models\Contracts\VisibleThroughRoot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um caso que casou com um perfil — com os motivos, o score e o que foi feito. */
class AlertMatch extends Model implements VisibleThroughRoot
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'release_at' => 'datetime',
            'matched_at' => 'datetime',
            'notified_at' => 'datetime',
            'feedback_at' => 'datetime',
            'ai_reviewed_at' => 'datetime',
            'feedback_note_flagged' => 'boolean',
            'feedback_unaffected' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AlertProfile::class, 'profile_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(NewsCase::class, 'case_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    /** Passou nos filtros do perfil: é um alerta para o cliente. */
    public function scopeRelevant(Builder $query): Builder
    {
        return $query->whereNull('suppressed_reason');
    }

    public function isRelevant(): bool
    {
        return $this->suppressed_reason === null;
    }

    public function hasBrandReason(): bool
    {
        return collect($this->reasons ?? [])->contains(fn ($r) => ($r['type'] ?? null) === 'brand');
    }

    /** "Fortinet, FortiOS" — os termos que casaram, sem repetir. */
    public function reasonTerms(int $limit = 3): string
    {
        $terms = collect($this->reasons ?? [])->pluck('label')->filter()->unique()->values();
        $text = $terms->take($limit)->implode(', ');

        return $terms->count() > $limit ? $text.' +'.($terms->count() - $limit) : $text;
    }

    public function suppressedLabel(): ?string
    {
        return match ($this->suppressed_reason) {
            'below_level' => 'abaixo do nível',
            'type_filter' => 'fora dos tipos escolhidos',
            'low_score' => 'score abaixo do mínimo',
            'ai_low_relevance' => 'segurado pela IA',
            null => null,
            default => $this->suppressed_reason,
        };
    }

    public function heldByAi(): bool
    {
        return $this->suppressed_reason === 'ai_low_relevance';
    }

    /** "Linux — produto afetado", um por motivo do MatchEngine, para o painel "Motivo". */
    public function reasonLines(): array
    {
        $kinds = [
            'brand' => 'cita a sua empresa',
            'stack' => 'produto da sua stack afetado',
            'mention' => 'citado no texto da notícia',
            'sector' => 'notícia do seu setor',
            'cve' => 'CVE junto com a sua stack',
            'region' => 'envolve onde você opera',
        ];

        return collect($this->reasons ?? [])
            ->map(fn ($r) => ($r['type'] ?? '') === 'region'
                ? 'Sua região'
                : trim(($r['label'] ?? '').' — '.($kinds[$r['type'] ?? ''] ?? ($r['type'] ?? '')), ' —'))
            ->filter()
            ->values()
            ->all();
    }

    /** "IA: 86% relevante · Afeta o FortiOS da sua stack." (null se não passou pela IA). */
    public function aiVerdict(): ?string
    {
        if (! $this->ai_reviewed_at) {
            return null;
        }

        // Sem nota, a razão já diz que a IA não respondeu.
        if ($this->ai_relevance === null) {
            return $this->ai_reason;
        }

        return "IA: {$this->ai_relevance}% relevante".($this->ai_reason ? " · {$this->ai_reason}" : '');
    }

    /**
     * O que aconteceu com o alerta. `$apps` são os canais do cliente
     * ("Slack", "Discord", "Slack e Discord", "Telegram, Slack e Discord"),
     * para dizer onde chegou.
     */
    public function deliveryLabel(string $apps = 'Slack'): string
    {
        $in = 'no '.str_replace([', ', ' e '], [', no ', ' e no '], $apps);
        $broken = str_contains($apps, ' e ') ? 'canal com problema' : "{$apps} com problema";

        return match ($this->delivery_status) {
            'sent' => match ($this->delivery_note) {
                'grouped' => "{$in}, agrupado",
                'resent' => "reenviado {$in}",
                default => "{$in}, na hora",
            },
            'held' => $this->delivery_note === 'quiet_hours' ? 'segurado pelo horário silencioso' : 'segurado pelo limite por hora',
            'skipped' => match ($this->delivery_note) {
                'realtime_off' => 'vai no resumo diário',
                'no_channel' => 'sem canal conectado',
                'channel_broken' => $broken,
                'channel_paused' => 'envio pausado em Notificadores',
                'type_off' => 'notícias desligadas em Notificadores',
                'paused' => 'perfil pausado',
                'backfill' => 'histórico, de antes de ativar',
                'merged' => 'enviado junto com outro alerta seu',
                'hidden' => 'a matéria saiu do feed público',
                'company_paused' => 'envio pausado: a empresa está suspensa',
                'not_visible' => 'quem recebe já não vê este alerta',
                default => 'não enviado',
            },
            'failed' => "falhou {$in}",
            default => $this->delivery_note === 'ai_review' ? 'em análise pela IA' : 'na fila',
        };
    }

    public function visibilityRoot(): ?Model
    {
        return $this->profile;
    }

    /** O que a conta vê: os matches dos alertas de Notícias que ela vê. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn($this->qualifyColumn('profile_id'), AlertProfile::query()->visibleTo($user)->select('id'));
    }
}
