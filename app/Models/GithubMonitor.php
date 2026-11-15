<?php

namespace App\Models;

use App\Models\Concerns\VisibleToAccount;
use App\Models\Contracts\VisibilityRoot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Monitoramento do GitHub: as respostas da entrevista própria, o que a IA
 * entendeu do texto "Adicional" e o estado da varredura diária. Um por conta
 * pessoal; na empresa, vários (até `limit_github_monitors`, P6), cada um com o
 * seu setor.
 */
class GithubMonitor extends Model implements VisibilityRoot
{
    use VisibleToAccount;

    /** Empresa e setor só pelo serviço (Visibility::stampFor + forceFill), nunca de um array da tela. */
    protected $guarded = [];

    public const LEVELS = ['critical', 'high', 'medium', 'low'];

    /**
     * O que a varredura procura. `kinds`: os achados que cada verificação gera.
     * Tudo começa ligado.
     */
    public const CHECKS = [
        'segredos' => [
            'label' => 'Chaves e tokens vazados',
            'desc' => 'Os nomes das suas chaves e os prefixos dos seus tokens em código público.',
            'kinds' => ['key_leak', 'token_prefix', 'extra_term'],
        ],
        'codigo_privado' => [
            'label' => 'Código privado publicado',
            'desc' => 'Repositório público com o nome de um repositório privado seu.',
            'kinds' => ['private_repo'],
        ],
        'organizacao' => [
            'label' => 'Sua organização',
            'desc' => 'Repositório que ficou público de repente e arquivos sensíveis (.env, chaves privadas) nos públicos.',
            'kinds' => ['org_new_repo', 'sensitive_file'],
        ],
        'marca' => [
            'label' => 'Repositórios com a sua marca',
            'desc' => 'Clones, kits de phishing, robôs e cópias do site que usam o nome da empresa.',
            'kinds' => ['brand_repo'],
        ],
        'emails' => [
            'label' => 'E-mails corporativos com senha',
            'desc' => 'Endereços @empresa junto de "password" ou "senha" em código público.',
            'kinds' => ['email_leak'],
        ],
        'enderecos' => [
            'label' => 'Endereços internos',
            'desc' => 'Hosts que só deveriam existir dentro da empresa (Jenkins, banco, API interna).',
            'kinds' => ['hostname_leak'],
        ],
    ];

    /** Limites da entrevista: o bastante para uma empresa, sem virar busca genérica. */
    public const MAX = [
        'orgs' => 5,
        'private_repos' => 15,
        'public_repos' => 30,
        'key_names' => 20,
        'token_prefixes' => 5,
        'hostnames' => 10,
        'email_domains' => 3,
        'brands' => 5,
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'orgs' => 'array',
            'private_repos' => 'array',
            'public_repos' => 'array',
            'key_names' => 'array',
            'token_prefixes' => 'array',
            'hostnames' => 'array',
            'email_domains' => 'array',
            'brands' => 'array',
            'checks' => 'array',
            'extra_result' => 'array',
            'extra_at' => 'datetime',
            'known_repos' => 'array',
            'last_scan_at' => 'datetime',
            'next_scan_at' => 'datetime',
            'manual_scan_at' => 'datetime',
            'stats' => 'array',
            'last_log' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Parâmetro que diz qual monitoramento uma URL mira. A conta pessoal tem um só e continua com as URLs de sempre; o
     */
    public function urlParams(array $extra = []): array
    {
        return $extra;
    }

    /** Como as telas e a Atividade chamam o monitoramento: a 1ª organização, a 1ª marca ou o número. */
    public function displayName(): string
    {
        $org = collect($this->orgs ?? [])->first();

        return $org ? 'github.com/'.$org : (collect($this->brands ?? [])->first() ?? 'Monitoramento '.$this->id);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(GithubFinding::class, 'monitor_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /** A verificação está ligada? O monitoramento nasce com todas ligadas. */
    public function wants(string $check): bool
    {
        return in_array($check, $this->checks ?? array_keys(self::CHECKS), true);
    }

    /** O tipo de achado está em alguma verificação ligada? */
    public function wantsKind(string $kind): bool
    {
        foreach (self::CHECKS as $check => $info) {
            if (in_array($kind, $info['kinds'], true) && $this->wants($check)) {
                return true;
            }
        }

        return false;
    }

    /** Níveis que passam para a segunda análise da IA e podem virar aviso. @return list<string> */
    public function levelsSent(): array
    {
        $index = array_search($this->min_level, self::LEVELS, true);

        return array_slice(self::LEVELS, 0, $index === false ? 2 : $index + 1);
    }

    public function reaches(?string $level): bool
    {
        return $level !== null && in_array($level, $this->levelsSent(), true);
    }

    public function levelLabel(): string
    {
        return match ($this->min_level) {
            'critical' => 'Somente Critical',
            'high' => 'High e acima',
            'medium' => 'Medium e acima',
            default => 'Todos os níveis',
        };
    }

    /** O que a IA tirou do texto "Adicional" (vazio se não há texto, ou ele não foi para a IA). */
    public function extra(string $field): array
    {
        return $this->extra_status === 'done' ? array_values((array) ($this->extra_result[$field] ?? [])) : [];
    }

    /** O resumo do texto "Adicional" em uma frase, como a IA entendeu. */
    public function extraContext(): ?string
    {
        return $this->extra_status === 'done' ? (($this->extra_result['context'] ?? null) ?: null) : null;
    }

    /** Respostas da entrevista somadas ao que a IA tirou do texto "Adicional". @return list<string> */
    public function combined(string $field): array
    {
        $extra = match ($field) {
            'key_names', 'token_prefixes', 'hostnames' => $this->extra($field),
            'private_repos' => $this->extra('repositories'),
            default => [],
        };

        $seen = [];

        foreach ([...($this->{$field} ?? []), ...$extra] as $value) {
            $seen[mb_strtolower((string) $value)] ??= (string) $value;
        }

        return array_values($seen);
    }

    /** Organizações e usuários oficiais, em minúsculas, para comparar com o dono de um repositório. @return list<string> */
    public function ownLogins(): array
    {
        $owners = array_map(fn ($repo) => explode('/', (string) $repo)[0], array_filter($this->public_repos ?? [], fn ($r) => str_contains((string) $r, '/')));

        return array_values(array_unique(array_map('mb_strtolower', [...($this->orgs ?? []), ...$owners])));
    }

    /** O repositório é da própria empresa (organização oficial ou repositório público oficial)? */
    public function owns(string $fullName): bool
    {
        $fullName = mb_strtolower($fullName);
        $owner = explode('/', $fullName)[0];

        return in_array($owner, $this->ownLogins(), true)
            || in_array($fullName, array_map('mb_strtolower', $this->public_repos ?? []), true);
    }

    /** Quando o "Verificar agora" volta a valer (nulo: já vale). */
    public function manualScanAvailableAt(): ?Carbon
    {
        $at = $this->manual_scan_at?->copy()->addHours((int) config('secmon.github.manual_scan_hours', 24));

        return $at && $at->isFuture() ? $at : null;
    }

    public function appendLog(string $line): void
    {
        $log = $this->last_log ?? [];
        $log[] = now()->timezone('America/Sao_Paulo')->format('H:i:s').' '.$line;
        $this->last_log = array_slice($log, -40);
    }
}
