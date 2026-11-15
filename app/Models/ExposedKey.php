<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma chave achada no site de um domínio comprovado, pela varredura de chaves (`scan`) ou pelo
 * mapeamento dos testes (`pentest`): só mascarada, com a impressão digital, e o que o dono decidiu.
 */
class ExposedKey extends Model
{
    protected $guarded = [];

    /** `allowed`: pode ficar exposta; `leak`: não deveria estar exposta. */
    public const VERDICTS = ['allowed', 'leak'];

    public const SOURCES = ['scan', 'pentest'];

    protected function casts(): array
    {
        return [
            'locations' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'verdict_at' => 'datetime',
        ];
    }

    public function ownedDomain(): BelongsTo
    {
        return $this->belongsTo(OwnedDomain::class);
    }
}
