<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Pergunta da entrevista. `options` é [{value, label, desc?, tech?, terms?, codes?}]:
 * `tech` liga a opção a uma tecnologia do catálogo, `terms` e `codes` são o que
 * a opção significa na busca (setores e regiões).
 */
class InterviewQuestion extends Model
{
    protected $guarded = [];

    public const STEPS = [
        1 => ['Empresa', 'nome, marcas, setor'],
        2 => ['Desenvolvimento', 'linguagens e frameworks'],
        3 => ['Infraestrutura', 'servidores, nuvem, bancos'],
        4 => ['Rede e ferramentas', 'VPN, e-mail, site, SaaS'],
        5 => ['Domínio', 'descoberta automática'],
        6 => ['Alertas', 'nível e tipos'],
        7 => ['Revisão', 'prévia de 30 dias'],
    ];

    public const KINDS = [
        'text' => 'texto',
        'tags' => 'lista de termos',
        'single' => 'escolha única',
        'multi' => 'múltipla escolha',
        'catalog' => 'múltipla escolha (catálogo)',
        'boolean' => 'sim / não',
        'versions' => 'versões',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'show_if' => 'array',
            'required' => 'boolean',
            'active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('step')->orderBy('position')->orderBy('id');
    }

    public function isMultiple(): bool
    {
        return in_array($this->kind, ['multi', 'catalog', 'tags'], true);
    }

    /** Rótulo do tipo, com "sim / não" quando a escolha única é só isso. */
    public function kindLabel(): string
    {
        $values = array_column($this->options ?? [], 'value');

        if ($this->kind === 'single' && array_diff($values, ['sim', 'nao', 'nao_sei']) === []) {
            return 'sim / não';
        }

        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function option(string $value): ?array
    {
        foreach ($this->options ?? [] as $option) {
            if (($option['value'] ?? null) === $value) {
                return $option;
            }
        }

        return null;
    }
}
