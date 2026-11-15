<?php

namespace App\Models;

use App\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

/**
 * A conta dentro da empresa (`company_user`): o papel, quando foi convidada, quando entrou e,
 * se a admin a removeu, desde quando está desativada (§4.6). A linha fica: a conta removida não
 * vira conta pessoal.
 */
class CompanyMember extends Pivot
{
    protected $table = 'company_user';

    public $incrementing = true;

    /** Papel, empresa e datas só pelo código que decide (forceCreate/forceFill), nunca de um array da tela. */
    protected $guarded = ['*'];

    /**
     * A linha nunca sai sozinha: sem ela a conta de empresa pareceria pessoal (R12) e a empresa podia
     * ficar sem admin. A conta removida é desativada (§4.6) e a linha só some junto com a conta (cascata).
     */
    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('A conta de empresa não sai da empresa apagando a linha: desative (§4.6) ou apague a conta.'));
    }

    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }
}
