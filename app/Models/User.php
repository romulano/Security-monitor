<?php

namespace App\Models;

use App\Enums\CompanyRole;
use App\Services\Auth\EmailVerification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

/** Os alertas pedem o e-mail confirmado (middleware `verified`); o link sai pelo EmailVerification. */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // O token do TXT de posse: só as telas de posse o mostram (Red Team da E1a).
        'domain_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            // A liberação dos testes de segurança pelo admin (PentestAccess).
            'pentest_access_requested_at' => 'datetime',
            'pentest_access_granted_at' => 'datetime',
            'pentest_access_revoked_at' => 'datetime',
        ];
    }

    public function hasGithub(): bool
    {
        return $this->github_id !== null;
    }

    /** O link de confirmação sai pela porta de todo e-mail (Outbox), não pela notificação padrão. */
    public function sendEmailVerificationNotification(): void
    {
        app(EmailVerification::class)->send($this);
    }

    /**
     * Derruba as sessões abertas da conta (as sessões ficam no banco). `$except`
     * poupa a de quem está pedindo, quando o admin troca a própria senha.
     */
    public function endSessions(?string $except = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $this->id)
            ->when($except, fn ($query) => $query->where('id', '!=', $except))
            ->delete();
    }

    /**
     * A conta de empresa (R12): a linha dela em `company_user`. A conta pessoal não tem, e nunca
     * ganha: a conta de empresa nasce dentro da empresa (Companies), e a pessoal não é convertida.
     */
    public function companyMembership(): HasOne
    {
        return $this->hasOne(CompanyMember::class);
    }

    public function company(): HasOneThrough
    {
        return $this->hasOneThrough(Company::class, CompanyMember::class, 'user_id', 'id', 'id', 'company_id');
    }

    public function isCompanyAccount(): bool
    {
        return $this->companyMembership !== null;
    }

    /** Os setores em que a conta está (R3). A admin não tem nenhum: responde por todos sem estar em cada um (D3). */
    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class)->withPivot('is_manager')->withTimestamps();
    }

    /** Os setores pelos quais a conta responde (§4.3); os da admin são todos os da empresa, sem linha aqui. */
    public function managedSectors(): BelongsToMany
    {
        return $this->sectors()->wherePivot('is_manager', true);
    }

    /** O papel na empresa; nulo na conta pessoal. */
    public function companyRole(): ?CompanyRole
    {
        return $this->companyMembership?->role;
    }

    /** Alertas do cliente: um por produto ou site. */
    public function alertProfiles(): HasMany
    {
        return $this->hasMany(AlertProfile::class);
    }

    /** Quantos alertas de Notícias a conta criou: o limite (MAX_PER_USER) da conta pessoal; na empresa vale o da empresa (E5d). */
    public function alertProfilesCreated(): int
    {
        return $this->alertProfiles()->count();
    }

    /** O primeiro alerta: o que vale quando a URL não diz qual (e o nome da empresa nas listas). */
    public function alertProfile(): HasOne
    {
        return $this->hasOne(AlertProfile::class)->oldestOfMany();
    }

    public function slackChannel(): HasOne
    {
        return $this->hasOne(NotificationChannel::class)->whereNull('company_id')->where('type', 'slack');
    }

    public function discordChannel(): HasOne
    {
        return $this->hasOne(NotificationChannel::class)->whereNull('company_id')->where('type', 'discord');
    }

    public function telegramChannel(): HasOne
    {
        return $this->hasOne(NotificationChannel::class)->whereNull('company_id')->where('type', 'telegram');
    }

    /** Monitoramento do GitHub (um por cliente, com entrevista própria). */
    /** Radares de domínios do cliente, um por domínio (entrevista própria, separada da de notícias). */
    public function lookalikeWatches(): HasMany
    {
        return $this->hasMany(LookalikeWatch::class);
    }

    /** Quantos Radares a conta criou: o limite (MAX_PER_USER) é por quem cria até a E5d (§4.8). */
    public function lookalikeWatchesCreated(): int
    {
        return $this->lookalikeWatches()->count();
    }

    /**
     * A conta pessoal chegou aos 5 Radares dela? Na empresa não há limite por quem cria (E5d, P7): quem limita é o domínio
     * (um Radar por domínio comprovado da empresa, até o limite de domínios).
     */
    public function reachedRadarLimit(): bool
    {
        return ! $this->isCompanyAccount() && $this->lookalikeWatchesCreated() >= LookalikeWatch::MAX_PER_USER;
    }

    public function githubMonitor(): HasOne
    {
        return $this->hasOne(GithubMonitor::class);
    }

    /**
     * Os domínios da conta, com a posse (DomainOwnership): comprovada num serviço, vale em todos.
     * O TXT de todo nome novo leva o token da conta (`domain_token`).
     */
    public function ownedDomains(): HasMany
    {
        return $this->hasMany(OwnedDomain::class);
    }

    /** Domínios inscritos na stack de testes de segurança (no máximo 3). */
    public function pentestDomains(): HasMany
    {
        return $this->hasMany(PentestDomain::class);
    }

    /** As execuções dos testes de segurança da conta, de todos os domínios (também os que saíram dos testes). */
    public function pentestRuns(): HasMany
    {
        return $this->hasMany(PentestRun::class);
    }

    /**
     * Telegram, Slack e Discord, nessa ordem, e os webhooks depois, do mais antigo: os alertas vão para todos os ativos.
     * Só os **pessoais**: o canal da empresa que a conta conectou é da empresa (E5a), e nunca entra em "meus canais" (o
     * código de segurança, o aviso dos testes, a Integração). Ele se lê pelo `NotificationChannel::visibleTo`.
     */
    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class)->whereNull('company_id')->orderByRaw("type = 'webhook'")->orderBy('type', 'desc')->orderBy('id');
    }

    /** Os webhooks pessoais da conta (até 5), do mais antigo. */
    public function webhooks(): HasMany
    {
        return $this->hasMany(NotificationChannel::class)->whereNull('company_id')->where('type', 'webhook')->orderBy('id');
    }
}
