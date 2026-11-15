<?php

namespace App\Services\Links;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Uso único de um link assinado que não muda nada visível no item (o motivo
 * da avaliação): um registro por item e finalidade em `signed_link_uses`.
 * Chamar dentro da transação da gravação, para o registro sair junto com ela.
 */
class SignedLinkUses
{
    public const NOTE = 'motivo';

    /** Marca o uso; false se já tinha sido usado (inclusive por um envio simultâneo). */
    public function claim(Model $subject, string $purpose): bool
    {
        return DB::table('signed_link_uses')->insertOrIgnore([
            'subject_type' => $subject->getTable(),
            'subject_id' => $subject->getKey(),
            'purpose' => $purpose,
            'created_at' => now(),
        ]) === 1;
    }

    public function used(Model $subject, string $purpose): bool
    {
        return DB::table('signed_link_uses')
            ->where(['subject_type' => $subject->getTable(), 'subject_id' => $subject->getKey(), 'purpose' => $purpose])
            ->exists();
    }
}
