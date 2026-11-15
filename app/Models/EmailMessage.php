<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um e-mail enviado pelo Security Monitor e até onde ele chegou (ver a migration). */
class EmailMessage extends Model
{
    /** Situação → rótulo do Admin → E-mails. */
    public const STATUSES = [
        'na_fila' => 'na fila',
        'aceito' => 'no servidor de envio',
        'entregue' => 'entregue',
        'adiado' => 'adiado (tentando de novo)',
        'devolvido' => 'devolvido',
        'falhou' => 'falhou',
    ];

    /** As que contam como erro na tela. */
    public const ERRORS = ['adiado', 'devolvido', 'falhou'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'accepted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
