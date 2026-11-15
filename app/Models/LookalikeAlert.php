<?php

namespace App\Models;

use App\Models\Contracts\VisibleThroughRoot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um aviso do Radar: domínio novo já em Crítico ou Alto, ou que subiu de
 * nível. Antes de sair passa pela IA (como os alertas de notícia); abaixo do
 * corte fica "segurado pela IA" e só aparece na tela.
 */
class LookalikeAlert extends Model implements VisibleThroughRoot
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'photo' => 'array',
            'ai_reviewed_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function lookalike(): BelongsTo
    {
        return $this->belongsTo(LookalikeDomain::class, 'lookalike_id');
    }

    public function capture(): BelongsTo
    {
        return $this->belongsTo(LookalikeCapture::class, 'capture_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'ai_review' => 'em análise pela IA',
            'held' => 'segurado pela IA',
            'queued' => 'na fila de envio',
            'sent' => 'enviado',
            'failed' => 'falhou',
            default => match ($this->note) {
                'paused' => 'Radar pausado',
                'no_channel' => 'sem canal conectado',
                'realtime_off' => 'só no resumo diário',
                'channel_broken' => 'canal com problema',
                'channel_paused' => 'envio pausado em Notificadores',
                'type_off' => 'Radar desligado em Notificadores',
                'company_paused' => 'envio pausado: a empresa está suspensa',
                'not_visible' => 'quem recebe já não vê este Radar',
                'quiet_hours' => 'horário silencioso: vai no resumo',
                'throttled' => 'limite por hora: vai no resumo',
                default => 'não enviado',
            },
        };
    }

    public function visibilityRoot(): ?Model
    {
        return $this->lookalike?->watch;
    }

    /** Os avisos das imitações que a conta vê. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn($this->qualifyColumn('lookalike_id'), LookalikeDomain::query()->visibleTo($user)->select('id'));
    }
}
