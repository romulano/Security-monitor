<?php

namespace App\Services\Alerts\Webhook;

use App\Exceptions\ActionRefused;
use App\Models\MonitoredDomain;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Alerts\ChannelSettings;
use App\Services\Alerts\Notifier;
use App\Services\Domains\DomainOwner;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * /alertas/webhooks: cadastrar, mudar e excluir os webhooks da conta, cada
 * um com nome, URL, segredo, tipos, pausa e as preferências de envio dos outros apps.
 *
 * O segredo gerado aparece uma vez só, logo depois de criado: vai para a próxima
 * página pela sessão, criptografado (a sessão fica no banco), e depois a tela mostra
 * só o começo e o fim. Quem perdeu troca o segredo, e o anterior para de valer na hora.
 */
class WebhookConnector
{
    private const SECRET = 'webhook_secret';

    public function __construct(private ChannelSettings $settings, private WebhookOwnership $ownership, private Notifier $notifier) {}

    /** A tela de cadastro: os domínios já comprovados (valem sem pedir de novo). */
    public function newPage(User $user): array
    {
        return [
            'proven' => DomainOwner::account($user->id)->copies(MonitoredDomain::query())
                ->whereNotNull('verified_at')->orderBy('domain')->pluck('domain')->unique()->values(),
            'events' => MessageBuilder::EVENTS,
            'example' => WebhookExample::json(),
            'scope' => null,
        ];
    }

    /** A página de um webhook. */
    public function page(User $user, NotificationChannel $channel, Session $session): array
    {
        return $this->settings->page($user, $channel, false) + [
            'secret' => $this->revealSecret($session, $channel),
            'events' => MessageBuilder::EVENTS,
            'example' => WebhookExample::json(),
            'others' => $user->notificationChannels()->get()
                ->reject(fn (NotificationChannel $other) => $other->is($channel))->values(),
        ];
    }

    /**
     * Cadastra o webhook, já ligado à prova do domínio (instalação de um dono só: já comprovado,
     * nunca pendente). `$auth`: `gerar`, `escolher` (`$secret`) ou `nenhuma`.
     */
    public function create(User $user, string $name, string $url, string $auth, ?string $secret, Session $session): NotificationChannel
    {
        ['url' => $url, 'host' => $host] = self::url($url);
        $name = self::name($name);
        $secret = self::secretFor($auth, $secret);
        $channel = DB::transaction(function () use ($user, $name, $url, $host, $secret) {
            // A conta travada: dois cadastros ao mesmo tempo não duplicam nome, URL ou segredo.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $this->ensureUnique($user, null, $name, $url, $secret);

            $channel = (new NotificationChannel(NotificationChannel::defaults() + [
                'type' => 'webhook',
                'method' => 'json',
                'name' => $name,
                'webhook_url' => $url,
                'webhook_host' => $host,
                'webhook_secret' => $secret,
                'webhook_secret_at' => $secret !== null ? now() : null,
                'status' => 'pending',
            ]))->forceFill(['user_id' => $user->id]);
            $channel->save();

            $this->ownership->attach($channel, $user);

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
        $this->ensureUnique($channel->user, $channel, $name, $url, null);

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
        $this->ensureUnique($channel->user, $channel, null, null, $secret);
        $channel->forceFill(['webhook_secret' => $secret, 'webhook_secret_at' => $secret !== null ? now() : null])->save();

        if ($auth === 'gerar') {
            $this->flashSecret($session, $channel, (string) $secret);
        }
    }

    /** "Enviar teste": só com o domínio comprovado. Antes disso o Security Monitor não chama o endereço. */
    public function test(NotificationChannel $channel): void
    {
        if ($channel->status === 'pending') {
            throw new ActionRefused('Comprove o domínio antes de enviar o teste: até lá, o Security Monitor não chama o endereço.');
        }

        $this->notifier->test($channel);
    }

    /** Exclui o webhook e a cópia da prova de posse dele (a prova dos outros serviços fica). */
    public function disconnect(NotificationChannel $channel): void
    {
        DB::transaction(function () use ($channel) {
            $copy = $channel->domain;
            $channel->delete();
            $copy?->delete();
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

    /** Nome, URL e segredo não se repetem entre os webhooks da conta (cada um tem o próprio segredo). */
    private function ensureUnique(User $user, ?NotificationChannel $except, ?string $name, ?string $url, ?string $secret): void
    {
        $peers = NotificationChannel::query()->where('user_id', $user->id);

        foreach ($peers->where('type', 'webhook')->whereKeyNot($except?->id ?? 0)->get() as $other) {
            if ($name !== null && mb_strtolower((string) $other->name) === mb_strtolower($name)) {
                throw ValidationException::withMessages(['nome' => 'Você já tem um webhook com esse nome.']);
            }

            if ($url !== null && $other->webhook_url === $url) {
                throw ValidationException::withMessages(['url' => "Essa URL já está no {$other->appName()}."]);
            }

            if ($secret !== null && $other->webhook_secret !== null && hash_equals((string) $other->webhook_secret, $secret)) {
                throw ValidationException::withMessages(['segredo' => 'Esse valor já é o segredo de outro webhook seu: cada webhook tem o próprio.']);
            }
        }
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
