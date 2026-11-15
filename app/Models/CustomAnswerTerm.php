<?php

namespace App\Models;

use App\Services\Alerts\TermMatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * Resposta escrita na entrevista ("Outro") e os termos de busca que ela vira.
 * Enquanto a IA não gerou os termos, a própria resposta vale como termo.
 */
class CustomAnswerTerm extends Model
{
    protected $guarded = [];

    public const KINDS = [
        'sector' => 'setor',
        'region' => 'região',
        'attack_type' => 'tipo de ameaça',
        'tech' => 'tecnologia',
    ];

    protected function casts(): array
    {
        return [
            'terms' => 'array',
            'codes' => 'array',
            'edited_by_admin' => 'boolean',
        ];
    }

    /** Setores, regiões e tipos de ataque têm regra própria; o resto é tecnologia. */
    public static function kindFor(string $questionKey): string
    {
        return match ($questionKey) {
            'sectors' => 'sector',
            'regions' => 'region',
            'attack_types' => 'attack_type',
            default => 'tech',
        };
    }

    public static function normalize(string $label): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', TermMatcher::fold($label))), 0, 120);
    }
}
