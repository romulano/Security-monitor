<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Um setor da empresa (§4.1, R3): as contas dele em `sector_user` e quem responde pelo setor (`is_manager`). A admin
 * responde por todos os setores sem linha aqui (D3). Quem cria, renomeia e mexe nas contas é o serviço Sectors.
 */
class Sector extends Model
{
    /** Nome e empresa só pelo serviço (forceCreate/forceFill), nunca de um array da tela. */
    protected $guarded = ['*'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** As contas do setor (sem a admin), com a marca de responsável. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_manager')->withTimestamps();
    }
}
