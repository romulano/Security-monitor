<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma tecnologia que o cliente pode ter na stack.
 *
 * `terms` são as expressões procuradas nos casos (produtos afetados,
 * fornecedores, título e resumo); com `strict`, só em produtos afetados e
 * fornecedores. `detect` são as regras da descoberta pelo
 * domínio: [{type: header|cookie|html|mx|spf|ns, name?, pattern}], onde
 * `pattern` é texto (sem caixa) ou, com prefixo "re:", uma regex cujo primeiro
 * grupo, se houver, é a versão.
 */
class TechCatalog extends Model
{
    protected $table = 'tech_catalog';

    protected $guarded = [];

    public const CATEGORIES = [
        'language' => 'Linguagem',
        'framework' => 'Framework',
        'devops' => 'Código e CI/CD',
        'os' => 'Sistema operacional',
        'cloud' => 'Nuvem e hospedagem',
        'webserver' => 'Servidor web, proxy e CDN',
        'database' => 'Banco de dados',
        'virtualization' => 'Containers e virtualização',
        'network' => 'Firewall, VPN e borda',
        'identity' => 'E-mail e identidade',
        'tool' => 'Ferramenta',
        'endpoint' => 'Estação de trabalho',
        'cms' => 'CMS e e-commerce',
        'builder' => 'Criador de sites e apps',
        'email' => 'E-mail transacional e marketing',
        'dns' => 'DNS e domínio',
        'thirdparty' => 'Serviço de terceiro no site',
    ];

    public function isAutoDiscovered(): bool
    {
        return $this->origin === 'discovery';
    }

    protected function casts(): array
    {
        return [
            'terms' => 'array',
            'detect' => 'array',
            'strict' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(AlertProfile::class, 'alert_profile_techs', 'tech_id', 'profile_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
