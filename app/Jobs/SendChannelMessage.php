<?php

namespace App\Jobs;

use App\Models\AlertMatch;
use App\Models\GithubFinding;
use App\Models\LookalikeAlert;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Services\Access\DeliveryGate;
use App\Services\Alerts\Discord\DiscordResult;
use App\Services\Alerts\Slack\SlackResult;
use App\Services\Alerts\Telegram\TelegramResult;
use App\Services\Alerts\Webhook\WebhookResult;
use App\Services\Pentest\PentestReadyNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Entrega uma mensagem já montada, igual para qualquer app. Falha passageira
 * tenta de novo (1, 5 e 15 min); canal apagado ou acesso revogado marca o
 * canal como "precisa reconectar" e para; 429 espera o tempo que o app pedir.
 * Cada tentativa grava o código da resposta e quanto levou (`duration_ms`).
 */
abstract class SendChannelMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('notifications');
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /** Nome do app, para a mensagem da exceção que faz o job tentar de novo. */
    abstract protected function app(): string;

    /** Depois de uma entrega feita (o webhook zera a conta das falhas seguidas). */
    protected function delivered(NotificationDelivery $delivery): void {}

    /** Depois de uma entrega que falhou de vez, sem mais tentativa (o webhook conta as falhas seguidas). */
    protected function gaveUp(NotificationDelivery $delivery): void {}

    /** @param  callable(NotificationChannel, array): (SlackResult|DiscordResult|TelegramResult|WebhookResult)  $post */
    protected function deliver(callable $post): void
    {
        $delivery = NotificationDelivery::query()->with('channel')->find($this->deliveryId);

        if (! $delivery || $delivery->status === 'sent') {
            return;
        }

        $channel = $delivery->channel;

        // Plano empresarial (E2e, D7): empresa suspensa ou desligada, conta desativada ou que já não vê o item não
        // recebem nada, nem o teste; a decisão é relida aqui, com a entrega já na fila.
        if ($refusal = app(DeliveryGate::class)->forDelivery($delivery)) {
            $delivery->forceFill(['status' => 'skipped', 'error' => $refusal === DeliveryGate::PAUSED ? 'empresa suspensa: envios pausados' : 'quem recebe já não vê este alerta'])->save();
            $this->markMatches($delivery, 'skipped', $refusal);

            return;
        }

        // Canal quebrado só recebe o teste (é como o cliente confere a reconexão).
        if (! $channel->isActive() && $delivery->kind !== 'test') {
            $delivery->forceFill(['status' => 'skipped', 'error' => 'canal precisa reconectar'])->save();
            $this->markMatches($delivery, 'skipped', 'channel_broken');

            return;
        }

        // Pausado ou com o tipo desligado na Central depois de entrar na fila: não sai (o teste sai).
        if ($delivery->kind !== 'test' && ($skip = $channel->skipReason($this->type($delivery)))) {
            $delivery->forceFill(['status' => 'skipped', 'error' => $skip === 'channel_paused' ? 'envio pausado em Notificadores' : 'tipo desligado em Notificadores'])->save();
            $this->markMatches($delivery, 'skipped', $skip);

            return;
        }

        // O aviso dos testes só sai com a ligada dele em vigor e a autorização ainda pronta (M2 do Red Team no aviso).
        if ($delivery->kind === 'tests' && ! PentestReadyNotice::stillValid($delivery, now())) {
            $delivery->forceFill(['status' => 'skipped', 'error' => 'os testes foram desligados ou a autorização deixou de estar pronta'])->save();

            return;
        }

        $delivery->increment('attempts');
        $started = hrtime(true);
        $result = $post($channel, $delivery->payload ?? []);
        $delivery->forceFill(['response_code' => $result->status ?: null, 'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000)])->save();

        if ($result->ok) {
            $delivery->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null])->save();
            $channel->forceFill(['status' => 'active', 'broken_at' => null, 'last_error' => null, 'last_success_at' => now()])->save();
            $note = match (true) {
                $delivery->kind === 'grouped' => 'grouped',
                (bool) ($delivery->meta['resent'] ?? false) => 'resent',
                default => null,
            };
            $this->markMatches($delivery, 'sent', $note, now());
            $this->delivered($delivery);

            return;
        }

        $delivery->forceFill(['error' => $result->humanError()])->save();

        if ($result->retryAfter) {
            $this->release($result->retryAfter);

            return;
        }

        if ($result->permanent) {
            $delivery->forceFill(['status' => 'failed'])->save();
            $this->markMatches($delivery, 'failed');

            if ($result->breaksChannel) {
                $channel->forceFill(['status' => 'broken', 'broken_at' => now(), 'last_error' => $result->humanError()])->save();
            }

            $this->gaveUp($delivery);

            return;
        }

        throw new RuntimeException($this->app().': '.$result->error);
    }

    /** O tipo de alerta do envio, para a Central; o resumo junta todos e já sai montado para o canal. */
    private function type(NotificationDelivery $delivery): ?string
    {
        return match (true) {
            $delivery->kind === 'tests' => NotificationChannel::TESTS,
            isset($delivery->meta['lookalike_alert_id']), isset($delivery->meta['subdomain_alert_id']) => 'radar',
            isset($delivery->meta['github_finding_id']) => 'github',
            $delivery->match_id !== null || isset($delivery->meta['match_ids']) => 'news',
            default => null,
        };
    }

    public function failed(?Throwable $e): void
    {
        if ($delivery = NotificationDelivery::query()->find($this->deliveryId)) {
            $delivery->forceFill(['status' => 'failed'])->save();
            $this->markMatches($delivery, 'failed');
            $this->gaveUp($delivery);
        }
    }

    /**
     * Atualiza os alertas do envio. Com mais de um app, o mesmo alerta tem um
     * envio por canal: falha num não desfaz o "enviado" do outro.
     */
    private function markMatches(NotificationDelivery $delivery, string $status, ?string $note = null, $notifiedAt = null): void
    {
        // Aviso do Radar de domínios: um envio por canal, como os alertas de notícia.
        if ($alertId = $delivery->meta['lookalike_alert_id'] ?? null) {
            LookalikeAlert::query()->whereKey($alertId)
                ->when($status !== 'sent', fn ($q) => $q->where('status', '!=', 'sent'))
                ->update(array_filter(['status' => $status, 'note' => $status === 'skipped' ? $note : null, 'notified_at' => $notifiedAt, 'updated_at' => now()]));

            return;
        }

        // Achado do monitoramento do GitHub: idem.
        if ($findingId = $delivery->meta['github_finding_id'] ?? null) {
            GithubFinding::query()->whereKey($findingId)
                ->when($status !== 'sent', fn ($q) => $q->where('status', '!=', 'sent'))
                ->update(array_filter(['status' => $status, 'note' => $status === 'skipped' ? $note : null, 'notified_at' => $notifiedAt, 'updated_at' => now()]));

            return;
        }

        $ids = array_filter(array_merge([$delivery->match_id], $delivery->meta['match_ids'] ?? []));

        if ($ids === []) {
            return;
        }

        AlertMatch::query()
            ->whereIn('id', $ids)
            ->when($status !== 'sent', fn ($q) => $q->where('delivery_status', '!=', 'sent'))
            ->update(array_filter([
                'delivery_status' => $status,
                'delivery_note' => $note,
                'notified_at' => $notifiedAt,
                'release_at' => null,
            ], fn ($v, $k) => $v !== null || in_array($k, ['delivery_note', 'release_at'], true), ARRAY_FILTER_USE_BOTH));
    }
}
