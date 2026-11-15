<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Resposta crua de uma pergunta. A chave é composta (perfil + pergunta), então
 * a escrita passa por upsert no InterviewService; o model serve para leitura.
 */
class AlertProfileAnswer extends Model
{
    protected $primaryKey = null;

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
