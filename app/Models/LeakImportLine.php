<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma linha de dump que o parser não soube ler e espera um humano. */
class LeakImportLine extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * `resolved_by`, `resolved_at` e `leaked_credential_id` entram aqui porque quem os preenche é o
     * LeakPanel, com valores que ele mesmo monta — nunca entrada da requisição. Sem eles no
     * fillable, o `update()` da decisão os descartava calado e gravava `discarded` sem data, o que a
     * check constraint `leak_import_lines_resolution_check` recusava com 500.
     */
    protected $fillable = [
        'source', 'line_no', 'raw', 'reason', 'country', 'fields', 'fingerprint', 'status',
        'resolved_by', 'resolved_at', 'leaked_credential_id',
    ];

    protected $casts = [
        'fields' => 'array',
        'resolved_at' => 'datetime',
    ];

    /** O que o parser não conseguiu provar, em português, para a tela. */
    public const REASONS = [
        'ordem_ambigua' => 'endereço no fim e nenhum campo é e-mail: login e senha são intercambiáveis',
        'sem_endereco' => 'nenhum campo parece endereço',
        'dois_enderecos' => 'dois campos parecem endereço',
        'campos_de_menos' => 'menos de três campos',
        'campos_demais' => 'campos a mais do que a ordem explica',
        'campo_vazio' => 'login ou senha vazio',
        'campo_longo' => 'campo maior do que a coluna aceita',
    ];

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(LeakedCredential::class, 'leaked_credential_id');
    }
}
