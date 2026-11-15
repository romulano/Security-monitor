<?php

namespace App\Models;

use App\Models\Concerns\VisibleToAccount;
use App\Models\Contracts\VisibilityRoot;
use App\Services\Alerts\Slack\SlackClient;
use App\Services\Pentest\PentestAccess;
use App\Services\Pentest\PentestAvailability;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Canal do cliente: um Slack, um Discord e um Telegram no máximo (type), e até
 * 5 webhooks, cada um com as próprias preferências. Os alertas vão para todos
 * os canais ativos.
 *
 * Slack: method `webhook` ou `oauth`. Discord: `channel` (o bot num canal do
 * servidor), `dm` (mensagem direta para quem conectou) ou `webhook`.
 * Telegram: `private` (conversa com o bot) ou `group` (o bot num grupo).
 * Webhook (`json`): o Security Monitor chama a URL do cliente (`webhook_url`), num domínio
 * comprovado pela conta (`domain_id`, ver WebhookOwnership), com o JSON assinado
 * pelo `webhook_secret`. Nunca recebe código de segurança: não é conversa privada.
 * status: `active`, `broken` (o app recusou o envio; no webhook, também as
 * falhas seguidas) ou `pending` (Discord conectado ao servidor, mas o canal ainda
 * não foi escolhido; webhook com o domínio ainda sem comprovar).
 *
 * Central de alertas: `muted_types` são os tipos (TYPES, e o aviso dos testes de
 * segurança) que este app não recebe, e `paused_at` segura todo envio (menos o
 * teste) sem desconectar.
 *
 * Plano empresarial (E5a, DESENHO-5.4 §2.1): o canal pode ser da empresa — `company_id` com `sector_id` nulo é um canal
 * geral, com setor é do setor (R11). Aí `user_id` é só quem conectou: quem vê e quem configura é o Visibility (raiz da
 * visibilidade, como o alerta e o Radar), e o canal da empresa nunca é conversa privada (o banco também recusa).
 */
class NotificationChannel extends Model implements VisibilityRoot
{
    use VisibleToAccount;

    /** A empresa e o setor só por forceFill, nunca por um formulário (CompanyChannels::stamp). */
    protected $guarded = ['company_id', 'sector_id'];

    protected $hidden = ['webhook_url', 'bot_token', 'webhook_secret'];

    public const DAYS = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

    /** Os apps, nesta ordem nas telas. O webhook é o único que a conta pode ter mais de um. */
    public const APPS = ['slack' => 'Slack', 'discord' => 'Discord', 'telegram' => 'Telegram', 'webhook' => 'Webhook'];

    /** Tipos de alerta que se liga e desliga em cada app (em Notificadores). */
    public const TYPES = ['news' => 'Notícias', 'radar' => 'Radar de domínios', 'github' => 'GitHub'];

    /**
     * O aviso dos testes de segurança (a chave global ligou e a autorização está
     * pronta): também se liga e desliga em cada app, mas só aparece para a conta que
     * tem os testes liberados. Não é alerta: fica fora de TYPES e do "Recebe:".
     */
    public const TESTS = 'tests';

    /**
     * Os tipos que a pessoa escolhe na Central: os de alerta e, com os testes
     * liberados, o aviso deles.
     *
     * @return array<string, string>
     */
    public static function typesFor(User $user): array
    {
        return self::TYPES + (PentestAvailability::for($user) && PentestAccess::granted($user) ? [self::TESTS => 'Testes de segurança'] : []);
    }

    /** Fusos que o cliente pode escolher para o horário silencioso e o resumo diário. */
    public const TIMEZONES = [
        'America/Sao_Paulo' => 'Brasília (America/Sao_Paulo)',
        'America/Manaus' => 'Manaus (America/Manaus)',
        'America/Fortaleza' => 'Fortaleza (America/Fortaleza)',
        'America/Noronha' => 'Fernando de Noronha (America/Noronha)',
        'Europe/Lisbon' => 'Lisboa (Europe/Lisbon)',
        'UTC' => 'UTC',
    ];

    /** O canal pessoal do cliente neste app, novo com os padrões do admin se ainda não existe (nunca um da empresa). */
    public static function forUser(int $userId, string $type): self
    {
        return self::query()->whereNull('company_id')->firstOrNew(['user_id' => $userId, 'type' => $type], self::defaults());
    }

    /** As preferências de um canal novo: os padrões do admin (o webhook, que não usa o forUser, também). */
    public static function defaults(): array
    {
        return [
            'realtime' => true,
            'digest' => true,
            'digest_time' => (string) Settings::get('alerts.digest_default_time'),
            'digest_scope' => 'all',
            'digest_days' => [1, 2, 3, 4, 5],
            'timezone' => (string) Settings::get('alerts.default_timezone'),
            'muted_types' => [],
        ];
    }

    protected function casts(): array
    {
        return [
            'webhook_url' => 'encrypted',
            'bot_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'webhook_secret_at' => 'datetime',
            'realtime' => 'boolean',
            'digest' => 'boolean',
            'digest_days' => 'array',
            'muted_types' => 'array',
            'paused_at' => 'datetime',
            'last_success_at' => 'datetime',
            'broken_at' => 'datetime',
            'failures' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /** Canal da empresa (geral ou de setor), e não da conta. */
    public function isCompany(): bool
    {
        return $this->company_id !== null;
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'channel_id');
    }

    /** A cópia da prova de posse do domínio do webhook (só o webhook tem). */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(MonitoredDomain::class, 'domain_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /** Este tipo de alerta (news, radar, github) está ligado para este app na Central? */
    public function wants(string $type): bool
    {
        return ! in_array($type, $this->muted_types ?? [], true);
    }

    /** Recebe este tipo agora: conectado, sem pausa e com o tipo ligado. */
    public function receives(string $type): bool
    {
        return $this->skipReason($type) === null;
    }

    /** "notícias e Radar de domínios", "só notícias"... para o teste e as telas. */
    public function typesLabel(): string
    {
        $on = collect(self::TYPES)->filter(fn ($label, $type) => $this->wants($type))
            ->map(fn ($label, $type) => $type === 'news' ? 'notícias' : $label);

        return match ($on->count()) {
            0 => 'nada (tudo desligado em Notificadores)',
            count(self::TYPES) => $on->join(', ', ' e '),
            default => 'só '.$on->join(', ', ' e '),
        };
    }

    public function isDiscord(): bool
    {
        return $this->type === 'discord';
    }

    public function isTelegram(): bool
    {
        return $this->type === 'telegram';
    }

    public function isWebhook(): bool
    {
        return $this->type === 'webhook';
    }

    /**
     * Conversa só da pessoa (Telegram privado ou mensagem direta do Discord): ninguém mais lê o que chega.
     * O webhook nunca é: o JSON vai para um sistema automatizado, e o código de segurança (reautenticação,
     * aceite de um teste) não sai por ele (ReauthCodes só manda a quem é privado).
     */
    public function isPrivate(): bool
    {
        // O canal da empresa nunca é a conversa de uma pessoa (R11; o banco recusa, isto é a segunda trava).
        if ($this->isCompany()) {
            return false;
        }

        return ($this->isTelegram() && $this->method === 'private') || ($this->isDiscord() && $this->method === 'dm');
    }

    /** "Slack", "Discord", "Telegram"; o webhook leva o nome dele ("Webhook Produção"), porque a conta pode ter vários. */
    public function appName(): string
    {
        if ($this->isWebhook()) {
            return 'Webhook'.($this->name ? ' '.$this->name : '');
        }

        return self::APPS[$this->type] ?? ucfirst((string) $this->type);
    }

    /** Página de configuração deste canal. */
    public function settingsUrl(): string
    {
        if ($this->isWebhook()) {
            return route('alerts.webhooks.show', $this);
        }

        // O canal da empresa: a conta pode ter vários do mesmo app, então a tela diz qual (E5a2).
        return route(match ($this->type) {
            'discord' => 'alerts.discord',
            'telegram' => 'alerts.telegram',
            default => 'alerts.slack',
        }, $this->isCompany() ? ['canal' => $this->id] : []);
    }

    /** O que falta no canal `pending`: o canal do Discord ou a posse do domínio do webhook. */
    public function pendingLabel(): string
    {
        return $this->isWebhook() ? 'falta comprovar o domínio' : 'falta escolher o canal';
    }

    /** O webhook manda os cabeçalhos de autenticação (Security-Monitor-Notification e Security-Monitor-Signature)? */
    public function signs(): bool
    {
        return $this->isWebhook() && filled($this->webhook_secret);
    }

    /** O segredo do webhook é mostrado uma vez só: depois, a tela mostra só o começo e o fim. */
    public function maskedSecret(): string
    {
        $secret = (string) $this->webhook_secret;

        return match (true) {
            $secret === '' => '',
            str_starts_with($secret, 'secmon_whsec_') => 'secmon_whsec_…'.substr($secret, -4),
            default => substr($secret, 0, 2).'…'.substr($secret, -4),
        };
    }

    /**
     * Por que este canal não recebe agora (nota do alerta), ou nulo se pode
     * receber. Com `$type` (news, radar), também vale o que a Central desligou.
     */
    public function skipReason(?string $type = null): ?string
    {
        return match (true) {
            $this->status === 'pending' => 'no_channel',
            $this->status !== 'active' => 'channel_broken',
            $this->isPaused() => 'channel_paused',
            $type !== null && ! $this->wants($type) => 'type_off',
            default => null,
        };
    }

    /**
     * O webhook é segredo: a tela mostra só o final. No webhook do cliente, o host
     * dele e o final do caminho (a URL pode levar um token na query).
     */
    public function maskedWebhook(): string
    {
        $url = (string) $this->webhook_url;

        if ($url !== '' && $this->isWebhook()) {
            $rest = substr($url, strlen('https://'.$this->webhook_host));

            return $this->webhook_host.(in_array($rest, ['', '/'], true) ? '' : (strlen($rest) <= 6 ? $rest : '/…'.substr($rest, -4)));
        }

        return $url === '' ? '' : ($this->isDiscord() ? 'discord.com' : 'hooks.slack.com').'/…/'.substr($url, -4);
    }

    public function label(): string
    {
        if ($this->isWebhook()) {
            return $this->maskedWebhook();
        }

        if ($this->isTelegram()) {
            return $this->method === 'group'
                ? 'grupo '.($this->telegram_chat_title ?: 'do Telegram')
                : 'conversa privada'.($this->telegram_username ? ' com '.$this->telegram_username : '');
        }

        if ($this->isDiscord()) {
            return match ($this->method) {
                'dm' => 'mensagem direta'.($this->discord_username ? ' para '.$this->discord_username : ''),
                'webhook' => 'webhook '.$this->maskedWebhook(),
                default => $this->discord_channel_name
                    ? '#'.$this->discord_channel_name.($this->discord_guild_name ? ' · '.$this->discord_guild_name : '')
                    : ($this->discord_guild_name ?: 'servidor').' (canal a escolher)',
            };
        }

        return $this->slack_channel ?: match (true) {
            $this->method !== 'webhook' => 'Slack',
            SlackClient::isWorkflowUrl($this->webhook_url) => 'fluxo de trabalho '.$this->maskedWebhook(),
            default => 'webhook '.$this->maskedWebhook(),
        };
    }

    /** "pelo app", "por webhook", "mensagem direta"... para a linha de status. */
    public function methodLabel(): string
    {
        return match ($this->method) {
            'oauth' => 'pelo app',
            'channel' => 'pelo bot, num canal',
            'dm' => 'pelo bot, em mensagem direta',
            'private' => 'pelo bot, em conversa privada',
            'group' => 'pelo bot, num grupo',
            'json' => 'por webhook, em JSON',
            default => 'por webhook',
        };
    }

    public function localNow(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())->copy()->setTimezone($this->timezone ?: 'America/Sao_Paulo');
    }

    /**
     * Fim do horário silencioso se `$now` cai dentro dele (no fuso do canal),
     * já convertido para UTC; nulo fora do silêncio.
     */
    public function quietUntil(?Carbon $now = null): ?Carbon
    {
        if (! $this->quiet_start || ! $this->quiet_end || $this->quiet_start === $this->quiet_end) {
            return null;
        }

        $local = $this->localNow($now);
        $minutes = $local->hour * 60 + $local->minute;
        [$sh, $sm] = array_map('intval', explode(':', $this->quiet_start));
        [$eh, $em] = array_map('intval', explode(':', $this->quiet_end));
        $start = $sh * 60 + $sm;
        $end = $eh * 60 + $em;

        // Janela que atravessa a meia-noite (22:00–07:00) ou não (13:00–14:00).
        $inside = $start < $end ? ($minutes >= $start && $minutes < $end) : ($minutes >= $start || $minutes < $end);

        if (! $inside) {
            return null;
        }

        $until = $local->copy()->setTime($eh, $em);

        if ($until->lessThanOrEqualTo($local)) {
            $until->addDay();
        }

        return $until->utc();
    }

    public function daysLabel(): string
    {
        $days = array_map('intval', $this->digest_days ?? []);
        sort($days);

        return match (true) {
            $days === [1, 2, 3, 4, 5] => 'seg a sex',
            $days === [1, 2, 3, 4, 5, 6, 7] => 'todos os dias',
            $days === [] => 'nenhum dia',
            default => implode(', ', array_map(fn ($d) => mb_strtolower(self::DAYS[$d] ?? ''), $days)),
        };
    }
}
