<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A conta do Security Monitor. Na versão local e aberta existe uma só, a do dono da instalação: ela nasce no primeiro
 * acesso (localOwner) e nunca pede senha nem confirmação de e-mail.
 */
class User extends Authenticatable
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
        // Legado: o token do TXT de posse não é mais gerado nem exibido (instalação de um dono só).
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
        ];
    }

    /**
     * A conta única desta instalação, criada no primeiro acesso. O e-mail e o nome vêm do ambiente
     * (LOCAL_OWNER_EMAIL, LOCAL_OWNER_NAME) para quem quiser os avisos por e-mail no endereço certo.
     */
    public static function localOwner(): self
    {
        $email = (string) config('secmon.local_owner.email');

        $owner = static::query()->firstOrCreate(
            ['email' => $email],
            ['name' => (string) config('secmon.local_owner.name'), 'password' => null, 'email_verified_at' => now()],
        );

        if (! $owner->is_admin) {
            $owner->forceFill(['is_admin' => true])->save();
        }

        return $owner;
    }

    /** Alertas do cliente: um por produto ou site. */
    public function alertProfiles(): HasMany
    {
        return $this->hasMany(AlertProfile::class);
    }

    /** O primeiro alerta: o que vale quando a URL não diz qual (e o nome da empresa nas listas). */
    public function alertProfile(): HasOne
    {
        return $this->hasOne(AlertProfile::class)->oldestOfMany();
    }

    public function slackChannel(): HasOne
    {
        return $this->hasOne(NotificationChannel::class)->where('type', 'slack');
    }

    public function discordChannel(): HasOne
    {
        return $this->hasOne(NotificationChannel::class)->where('type', 'discord');
    }

    public function telegramChannel(): HasOne
    {
        return $this->hasOne(NotificationChannel::class)->where('type', 'telegram');
    }

    /** Monitoramento do GitHub (um por cliente, com entrevista própria). */
    /** Radares de domínios do cliente, um por domínio (entrevista própria, separada da de notícias). */
    public function lookalikeWatches(): HasMany
    {
        return $this->hasMany(LookalikeWatch::class);
    }

    public function githubMonitor(): HasOne
    {
        return $this->hasOne(GithubMonitor::class);
    }

    /**
     * Os domínios da conta, com a posse (DomainOwnership): comprovada num serviço, vale em todos.
     * Instalação de um dono só: todo domínio nasce comprovado, sem prova por TXT no DNS.
     */
    public function ownedDomains(): HasMany
    {
        return $this->hasMany(OwnedDomain::class);
    }

    /** Domínios inscritos na stack de testes de segurança. */
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
        return $this->hasMany(NotificationChannel::class)->orderByRaw("type = 'webhook'")->orderBy('type', 'desc')->orderBy('id');
    }

    /** Os webhooks pessoais da conta, do mais antigo. */
    public function webhooks(): HasMany
    {
        return $this->hasMany(NotificationChannel::class)->where('type', 'webhook')->orderBy('id');
    }
}
