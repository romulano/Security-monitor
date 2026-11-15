<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Log de cada envio: base do painel de entregas e do reenvio. */
class NotificationDelivery extends Model
{
    protected $guarded = [];

    public const KINDS = [
        'realtime' => 'na hora',
        'grouped' => 'agrupado',
        'digest' => 'resumo',
        'test' => 'teste',
        'code' => 'código de confirmação',
        'tests' => 'aviso dos testes',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'meta' => 'array',
            'digest_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'channel_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(AlertMatch::class, 'match_id');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'sent' => $this->attempts > 1 ? "entregue na {$this->attempts}ª tentativa" : 'entregue',
            'failed' => 'falhou',
            'skipped' => 'pulado',
            default => 'na fila',
        };
    }
}
