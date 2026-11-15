<?php

namespace App\Models;

use App\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um convite para entrar numa empresa (CompanyInvites). O token não fica aqui, só o hash. */
class CompanyInvite extends Model
{
    /** Empresa, papel, prazo e token só pelo CompanyInvites, nunca de um array da tela. */
    protected $guarded = ['*'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'sector_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Nem aceito nem cancelado (pode estar vencido: reenviar renova). */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at');
    }

    /** Aberto e no prazo: ocupa uma vaga de conta. */
    public function scopePending(Builder $query): void
    {
        $query->open()->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
