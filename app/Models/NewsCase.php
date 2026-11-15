<?php

namespace App\Models;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class NewsCase extends Model
{
    use HasFactory;

    /**
     * Por que a matéria saiu do feed público (`hidden_reason`). Escondida é o
     * `is_published` falso: fora da busca, do painel, da API, dos alertas e da
     * fusão de matérias, sem apagar; o admin continua vendo.
     */
    public const HIDDEN_REASONS = [
        'off_topic_feed' => 'veio só do feed geral do TechCrunch, marcada como low: fora de segurança',
        'admin' => 'escondida pelo admin',
    ];

    protected $table = 'cases';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'indicators' => 'array',
            'victim_sectors' => 'array',
            'threat_actors' => 'array',
            'cves' => 'array',
            'first_at' => 'datetime',
            'latest_at' => 'datetime',
            'first_published_at' => 'datetime',
            'last_published_at' => 'datetime',
            'indexed_at' => 'datetime',
            'consolidated_at' => 'datetime',
            'vulnerability_explained_at' => 'datetime',
            'vulnerability_details' => 'array',
            'is_published' => 'boolean',
            'vulnerability_applies' => 'boolean',
            'hidden_at' => 'datetime',
        ];
    }

    /**
     * Só o falso gravado: a matéria recém-criada pelo CaseWriter ainda não leu
     * o padrão do banco (verdadeiro) e chega aqui com o campo vazio.
     */
    public function isHidden(): bool
    {
        return $this->is_published === false;
    }

    public function hide(string $reason): void
    {
        $this->forceFill(['is_published' => false, 'hidden_at' => now(), 'hidden_reason' => $reason])->save();
    }

    public function unhide(): void
    {
        $this->forceFill(['is_published' => true, 'hidden_at' => null, 'hidden_reason' => null])->save();
    }

    public function hiddenLabel(): ?string
    {
        return $this->isHidden() ? (self::HIDDEN_REASONS[$this->hidden_reason] ?? 'fora do feed público') : null;
    }

    protected static function booted(): void
    {
        static::creating(function (self $case) {
            $case->uuid ??= (string) Str::uuid();
            $case->slug ??= Str::limit(Str::slug($case->title ?? 'caso'), 90, '');
        });
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'case_id');
    }

    /** Exploits públicos dos CVEs do caso; o pivot `created_at` é quando o Security Monitor achou. */
    public function exploits(): BelongsToMany
    {
        return $this->belongsToMany(Exploit::class, 'case_exploit', 'case_id', 'exploit_id')->withPivot('created_at');
    }

    /** Matérias que a fusão de matérias apagou e que agora redirecionam para esta. */
    public function merges(): HasMany
    {
        return $this->hasMany(CaseMerge::class, 'case_id');
    }

    /**
     * Trava de quem reescreve o relatório da matéria (análise de um artigo
     * novo, fusão de matérias): a IA precisa ler o relatório já com a fonte
     * anterior, senão uma escrita apaga a outra.
     */
    public static function reportLock(int $caseId, int $seconds): Lock
    {
        return Cache::lock('case-report:'.$caseId, $seconds);
    }

    /** Valores de um grupo de indicadores, ex.: values('cves'). */
    public function indicatorValues(string $field): array
    {
        foreach ($this->indicators ?? [] as $group) {
            if (($group['field'] ?? null) === $field) {
                return array_values($group['values'] ?? []);
            }
        }

        return [];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
