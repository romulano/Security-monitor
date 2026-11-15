<?php

namespace App\Services\Alerts\Webhook;

use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\MonitoredDomain;
use App\Models\NotificationChannel;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Alerts\ChannelSettings;
use App\Services\Alerts\Notifier;
use App\Services\Company\ChannelScope;
use App\Services\Company\Companies;
use App\Services\Company\CompanyChannels;
use App\Services\Domains\DomainOwner;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * /alertas/webhooks: cadastrar, mudar e excluir os webhooks da conta (até 5), cada
 * um com nome, URL, segredo, tipos, pausa e as preferências de envio dos outros apps.
 *
 * O segredo gerado aparece uma vez só, logo depois de criado: vai para a próxima
 * página pela sessão, criptografado (a sessão fica no banco), e depois a tela mostra
 * só o começo e o fim. Quem perdeu troca o segredo, e o anterior para de valer na hora.
 *
 * Na empresa (E5a), o webhook é um canal da empresa, geral ou de um setor (ChannelScope): quem cria e o limite (10 por
 * empresa, e os 5 do escopo) são do CompanyChannels, e a posse do host é a da empresa, compatível com o escopo.
 */
class WebhookConnector
{
    private const SECRET = 'webhook_secret';

    public function __construct(private ChannelSettings $settings, private WebhookOwnership $ownership, private Notifier $notifier, private CompanyChannels $channels) {}

    public static function max(): int
    {
        return (int) config('secmon.alerts.webhook.max_per_user', 5);
    }

    /**
     * A tela de cadastro: quantos ainda cabem e os domínios já comprovados (valem sem pedir de novo). Na empresa, os do
     * escopo do webhook novo: os sem setor e, no setor, também os dele (a regra de compatibilidade, P1 do 5.3).
     */
    public function newPage(User $user, ?ChannelScope $scope = null): array
    {
        $company = $scope !== null && ! $scope->isPersonal();

        return [
            'left' => $company ? $this->companyLeft($scope) : max(0, self::max() - $user->webhooks()->count()),
            'proven' => ($company
                ? DomainOwner::company((int) $scope->companyId, $user->id, $scope->sectorId)->constrainFitting(OwnedDomain::query())
                : ($user->isCompanyAccount() ? OwnedDomain::query()->visibleTo($user) : DomainOwner::account($user->id)->copies(MonitoredDomain::query())))
                ->whereNotNull('verified_at')->orderBy('domain')->pluck('domain')->unique()->values(),
            'events' => MessageBuilder::EVENTS,
            'example' => WebhookExample::json(),
            // Na empresa, onde o webhook vai receber (E5a2): o campo `setor` do formulário e a frase da tela.
            'scope' => $company ? ['setor' => $scope->sectorId ?? 'geral', 'sector' => $this->channels->sectorLabel($scope)] : null,
        ];
    }

    /** A página de um webhook. */
    public function page(User $user, NotificationChannel $channel, Session $session): array
    {
        return $this->settings->page($user, $channel, false) + [
            'ownership' => $this->ownership->screen($channel),
            'secret' => $this->revealSecret($session, $channel),
            'events' => MessageBuilder::EVENTS,
            'example' => WebhookExample::json(),
            'others' => ($channel->isCompany() ? ChannelScope::of($channel)->constrain(NotificationChannel::query())->get() : $user->notificationChannels()->get())
                ->reject(fn (NotificationChannel $other) => $other->is($channel))->values(),
        ];
    }

    /**
     * Cadastra o webhook, já ligado à prova do domínio: ativo quando a conta já comprovou o domínio, pendente
     * (nada sai, nem o teste) até o cliente comprovar. `$auth`: `gerar`, `escolher` (`$secret`) ou `nenhuma`.
     * `$scope`: onde ele recebe; a conta de empresa sempre diz (os gerais ou um setor), a pessoal não precisa.
     */
    public function create(User $user, string $name, string $url, string $auth, ?string $secret, Session $session, ?ChannelScope $scope = null): NotificationChannel
    {
        ['url' => $url, 'host' => $host] = self::url($url);
        $name = self::name($name);
        $secret = self::secretFor($auth, $secret);
        // A conta de empresa não tem webhook pessoal (R11): sem escopo, recusa (antes da E5a o webhook era da conta).
        $scope ??= $user->isCompanyAccount() ? throw new ActionRefused('Escolha onde o webhook recebe: nos gerais da empresa ou num setor.') : ChannelScope::personal($user->id);

        $channel = DB::transaction(function () use ($user, $name, $url, $host, $secret, $scope) {
            if ($scope->isPersonal()) {
                // A conta travada: dois cadastros ao mesmo tempo não passam do limite.
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                if ($user->webhooks()->count() >= self::max()) {
                    throw new ActionRefused('Você chegou ao limite de '.self::max().' webhooks. Exclua um para cadastrar outro.');
                }
            }

            // Na empresa: quem, o setor, os 5 do escopo e os 10 da empresa, com a empresa travada.
            $owner = $this->channels->claim($user, $scope, 'webhook', 'json');
            $this->ensureUnique($scope, null, $name, $url, $secret);

            $channel = (new NotificationChannel(NotificationChannel::defaults() + [
                'type' => 'webhook',
                'method' => 'json',
                'name' => $name,
                'webhook_url' => $url,
                'webhook_host' => $host,
                'webhook_secret' => $secret,
                'webhook_secret_at' => $secret !== null ? now() : null,
                'status' => 'pending',
            ]))->forceFill($owner);
            $channel->save();

            $this->ownership->attach($channel, $user);
            $this->channels->record($user, $channel, 'channel.added');

            return $channel;
        });

        if ($auth === 'gerar') {
            $this->flashSecret($session, $channel, (string) $secret);
        }

        return $channel->refresh();
    }

    /**
     * Nome e URL (nula: fica a de agora, que a tela não mostra inteira). Outra URL recomeça a conta das falhas e
     * confere de novo a posse do host.
     */
    public function updateEndpoint(NotificationChannel $channel, User $user, string $name, ?string $url): void
    {
        ['url' => $url, 'host' => $host] = filled($url) ? self::url($url) : ['url' => (string) $channel->webhook_url, 'host' => (string) $channel->webhook_host];
        $name = self::name($name);
        $this->ensureUnique(ChannelScope::of($channel), $channel, $name, $url, null);

        DB::transaction(function () use ($channel, $user, $name, $url, $host) {
            if ($url === $channel->webhook_url) {
                $channel->forceFill(['name' => $name])->save();

                return;
            }

            $channel->forceFill([
                'name' => $name,
                'webhook_url' => $url,
                'webhook_host' => $host,
                'failures' => 0,
                ...($channel->status === 'broken' ? ['status' => 'active', 'broken_at' => null, 'last_error' => null] : []),
            ])->save();

            $this->ownership->attach($channel, $user);
        });
    }

    /** Troca (ou tira) o segredo: o anterior para de valer na hora. O gerado aparece uma vez na próxima página. */
    public function changeSecret(NotificationChannel $channel, User $user, string $auth, ?string $secret, Session $session): void
    {
        $secret = self::secretFor($auth, $secret);
        $this->ensureUnique(ChannelScope::of($channel), $channel, null, null, $secret);
        $channel->forceFill(['webhook_secret' => $secret, 'webhook_secret_at' => $secret !== null ? now() : null])->save();

        if ($auth === 'gerar') {
            $this->flashSecret($session, $channel, (string) $secret);
        }
    }

    /** Botão "Verificar": a posse do domínio (WebhookOwnership); sem o TXT, recusa dizendo o que falta. */
    public function verify(NotificationChannel $channel, User $user): void
    {
        $this->ownership->verify($channel, $user);
    }

    /** "Enviar teste": só com o domínio comprovado. Antes disso o Security Monitor não chama o endereço. */
    public function test(NotificationChannel $channel): void
    {
        if ($channel->status === 'pending') {
            throw new ActionRefused('Comprove o domínio antes de enviar o teste: até lá, o Security Monitor não chama o endereço.');
        }

        $this->notifier->test($channel);
    }

    /** Exclui o webhook e a cópia da prova de posse dele (a prova dos outros serviços fica). Na empresa, `$by` vai à Atividade. */
    public function disconnect(NotificationChannel $channel, ?User $by = null): void
    {
        DB::transaction(function () use ($channel, $by) {
            $copy = $channel->domain;
            $channel->delete();
            $copy?->delete();

            if ($by !== null) {
                $this->channels->record($by, $channel, 'channel.removed');
            }
        });
    }

    /** @return array{url: string, host: string} */
    private static function url(string $input): array
    {
        try {
            return WebhookUrl::normalize($input);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }
    }

    private static function name(string $name): string
    {
        $name = Str::squish($name);

        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages(['nome' => 'Dê um nome de até 60 caracteres ao webhook, como “Produção” ou “SIEM”.']);
        }

        return $name;
    }

    private static function secretFor(string $auth, ?string $secret): ?string
    {
        if ($auth === 'gerar') {
            return WebhookSecret::generate();
        }

        if ($auth !== 'escolher') {
            return null;
        }

        $secret = trim((string) $secret);

        if ($problem = WebhookSecret::problem($secret)) {
            throw ValidationException::withMessages(['segredo' => $problem]);
        }

        return $secret;
    }

    /**
     * Nome, URL e segredo não se repetem entre os webhooks da conta (cada um tem o próprio segredo). Na empresa, o nome
     * não se repete no escopo, e a URL e o segredo, na empresa toda — sem dizer qual webhook de outro setor já os usa.
     */
    private function ensureUnique(ChannelScope $scope, ?NotificationChannel $except, ?string $name, ?string $url, ?string $secret): void
    {
        $peers = $scope->isPersonal()
            ? $scope->constrain(NotificationChannel::query())
            : NotificationChannel::query()->where('company_id', $scope->companyId);

        foreach ($peers->where('type', 'webhook')->whereKeyNot($except?->id ?? 0)->get() as $other) {
            $here = ChannelScope::of($other) == $scope;

            if ($name !== null && $here && mb_strtolower((string) $other->name) === mb_strtolower($name)) {
                throw ValidationException::withMessages(['nome' => $scope->isPersonal() ? 'Você já tem um webhook com esse nome.' : 'Já existe um webhook com esse nome aqui.']);
            }

            if ($url !== null && $other->webhook_url === $url) {
                throw ValidationException::withMessages(['url' => $here ? "Essa URL já está no {$other->appName()}." : 'Essa URL já está em outro webhook da empresa.']);
            }

            if ($secret !== null && $other->webhook_secret !== null && hash_equals((string) $other->webhook_secret, $secret)) {
                throw ValidationException::withMessages(['segredo' => 'Esse valor já é o segredo de outro webhook'.($scope->isPersonal() ? ' seu' : ' da empresa').': cada webhook tem o próprio.']);
            }
        }
    }

    /** Quantos webhooks ainda cabem neste escopo da empresa: os 5 do escopo e os 10 da empresa (sem travar; a trava é no claim). */
    private function companyLeft(ChannelScope $scope): int
    {
        $companies = app(Companies::class);
        $company = Company::query()->findOrFail($scope->companyId);
        $inScope = $companies->limit($company, (string) $scope->limitName()) - $scope->constrain(NotificationChannel::query())->count();
        $webhooks = $companies->limit($company, 'webhooks') - NotificationChannel::query()->where('company_id', $company->id)->where('type', 'webhook')->count();

        return max(0, min($inScope, $webhooks));
    }

    private function flashSecret(Session $session, NotificationChannel $channel, string $secret): void
    {
        $session->flash(self::SECRET, ['webhook' => $channel->id, 'value' => Crypt::encryptString($secret)]);
    }

    /** O segredo gerado agora, para a página logo depois do cadastro (ou da troca); nulo nas outras vezes. */
    private function revealSecret(Session $session, NotificationChannel $channel): ?string
    {
        $flash = $session->get(self::SECRET);

        if (! is_array($flash) || (int) ($flash['webhook'] ?? 0) !== $channel->id) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $flash['value']);
        } catch (Throwable) {
            return null;
        }
    }
}
