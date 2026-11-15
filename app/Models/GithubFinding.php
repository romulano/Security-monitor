<?php

namespace App\Models;

use App\Models\Contracts\VisibleThroughRoot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um achado do monitoramento do GitHub: um arquivo (vários termos do cliente
 * no mesmo arquivo viram um achado só) ou um repositório.
 *
 * Passa por duas etapas de IA, cada uma com o seu prompt: a classificação
 * (nível e certeza de ser um achado real) e, só para o que é achado no nível
 * escolhido pelo cliente, o parecer de envio (chance de ele querer o aviso),
 * que aprende com as avaliações dele.
 */
class GithubFinding extends Model implements VisibleThroughRoot
{
    protected $guarded = [];

    /**
     * O que cada nível significa neste monitoramento. Os mesmos nomes da
     * criticidade das notícias, para o cliente escolher do mesmo jeito.
     */
    public const LEVELS = [
        'critical' => [
            'title' => 'Segredo exposto ou código privado publicado',
            'desc' => 'Chave, token, senha ou chave privada da empresa com valor que parece real, ou um repositório privado que ficou público. Exige trocar a chave e pedir a remoção na hora.',
        ],
        'high' => [
            'title' => 'Exposição sensível, sem segredo confirmado',
            'desc' => 'Configuração interna em público (endereços internos, arquivo .env, e-mail corporativo junto de senha), valor que pode ser real, ou kit de phishing com a marca.',
        ],
        'medium' => [
            'title' => 'Vale olhar',
            'desc' => 'Referência às suas chaves sem o valor, cópias e forks do seu código, robôs e clientes não oficiais do seu serviço, repositório novo na sua organização.',
        ],
        'low' => [
            'title' => 'Menção sem risco aparente',
            'desc' => 'A marca ou o domínio em listas, documentação, SDKs de terceiros ou projetos homônimos.',
        ],
    ];

    /** As faixas da certeza da classificação (etapa 1). Abaixo de 40%, a IA descarta: não é achado. */
    public const CONFIDENCE = [
        90 => ['Confirmado', '90–100', 'Evidência direta: o valor está lá e o nome só pode ser da empresa.'],
        70 => ['Provável', '70–89', 'Tudo indica que é da empresa, mas falta uma confirmação.'],
        40 => ['Possível', '40–69', 'Precisa de alguém olhar: pode ser coincidência de nome.'],
        0 => ['Improvável', '0–39', 'Quase certo que é outra coisa: a IA descarta.'],
    ];

    /** Tipos de achado, do mais grave para o mais leve dentro de cada família (arquivo e repositório). */
    public const KINDS = [
        'sensitive_file' => 'Arquivo sensível num repositório seu',
        'key_leak' => 'Nome de chave sua em código público',
        'token_prefix' => 'Token com o prefixo dos seus',
        'email_leak' => 'E-mail corporativo junto de senha',
        'hostname_leak' => 'Endereço interno em código público',
        'extra_term' => 'Termo do seu texto adicional',
        'private_repo' => 'Repositório privado seu em público',
        'org_new_repo' => 'Repositório novo público na sua organização',
        'brand_repo' => 'Repositório que usa a sua marca',
    ];

    /** Achados que são um repositório inteiro (os outros são um arquivo). */
    public const REPO_KINDS = ['private_repo', 'org_new_repo', 'brand_repo'];

    /** Situação de cada achado, para a tela. */
    public const STATUSES = [
        'triage' => 'em classificação pela IA',
        'discarded' => 'descartado: não é achado',
        'below_level' => 'abaixo do nível escolhido',
        'ai_review' => 'em análise pela IA',
        'held' => 'segurado pela IA',
        'queued' => 'na fila de envio',
        'sent' => 'enviado',
        'failed' => 'falhou',
    ];

    protected function casts(): array
    {
        return [
            'terms' => 'array',
            'repo_fork' => 'boolean',
            'repo_created_at' => 'datetime',
            'repo_pushed_at' => 'datetime',
            'secrets' => 'array',
            'signals' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'changed_at' => 'datetime',
            'gone_at' => 'datetime',
            'triage_at' => 'datetime',
            'ai_reviewed_at' => 'datetime',
            'notified_at' => 'datetime',
            'feedback_at' => 'datetime',
            'feedback_note_flagged' => 'boolean',
            'feedback_unaffected' => 'boolean',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(GithubMonitor::class, 'monitor_id');
    }

    /** Mais grave primeiro, depois o mais recente. */
    public function scopeByLevel(Builder $query): Builder
    {
        return $query
            ->orderByRaw("case coalesce(triage_level, auto_level) when 'critical' then 0 when 'high' then 1 when 'medium' then 2 when 'low' then 3 else 4 end")
            ->latest('first_seen_at')
            ->orderByDesc('id');
    }

    /** O nível em vigor: o da IA ou, sem ela, o das regras. `none` = não é achado. */
    public function level(): string
    {
        return $this->triage_level ?? $this->auto_level;
    }

    public function isRepo(): bool
    {
        return in_array($this->kind, self::REPO_KINDS, true);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function heldByAi(): bool
    {
        return $this->status === 'held';
    }

    /** O nome da faixa de certeza da classificação ("Provável"). */
    public static function confidenceLabel(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }

        foreach (self::CONFIDENCE as $from => [$label]) {
            if ($score >= $from) {
                return $label;
            }
        }

        return null;
    }

    /** "repo/caminho/arquivo.env" ou só "repo", para títulos e mensagens. */
    public function location(): string
    {
        return $this->path ? $this->repo.'/'.$this->path : $this->repo;
    }

    public function statusLabel(): string
    {
        if ($this->status === 'skipped') {
            return match ($this->note) {
                'paused' => 'monitoramento pausado',
                'no_channel' => 'sem app conectado',
                'realtime_off' => 'só no resumo diário',
                'channel_broken' => 'app com problema',
                'channel_paused' => 'envio pausado em Notificadores',
                'type_off' => 'GitHub desligado em Notificadores',
                'company_paused' => 'envio pausado: a empresa está suspensa',
                'not_visible' => 'quem recebe já não vê este monitoramento',
                'quiet_hours' => 'horário silencioso: vai no resumo',
                'throttled' => 'limite por hora: vai no resumo',
                default => 'não enviado',
            };
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function visibilityRoot(): ?Model
    {
        return $this->monitor;
    }

    /** Os achados dos monitoramentos do GitHub que a conta vê. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn($this->qualifyColumn('monitor_id'), GithubMonitor::query()->visibleTo($user)->select('id'));
    }
}
