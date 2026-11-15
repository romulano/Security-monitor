<?php

namespace App\Services\Alerts;

use App\Jobs\SendDiscordMessage;
use App\Jobs\SendSlackMessage;
use App\Jobs\SendTelegramMessage;
use App\Jobs\SendWebhookMessage;
use App\Models\AlertMatch;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Services\Alerts\Discord\MessageBuilder as DiscordMessages;
use App\Services\Alerts\Slack\MessageBuilder as SlackMessages;
use App\Services\Alerts\Telegram\MessageBuilder as TelegramMessages;
use App\Services\Alerts\Webhook\MessageBuilder as WebhookMessages;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registra cada envio em notification_deliveries (com o payload, para o admin
 * ver e reenviar) e enfileira a entrega na fila `notifications`. A mensagem é
 * montada no formato do app do canal: Block Kit no Slack, embed no Discord,
 * HTML com botões no Telegram, JSON versionado no webhook.
 */
class Notifier
{
    public function __construct(
        private SlackMessages $slack,
        private DiscordMessages $discord,
        private TelegramMessages $telegram,
        private WebhookMessages $webhook,
        private DigestBuilder $digests,
    ) {}

    /** `$resent`: o cliente pediu de novo pelo histórico ("Reenviar" em Meus alertas). */
    public function alert(AlertMatch $match, NotificationChannel $channel, bool $resent = false): NotificationDelivery
    {
        $payload = $this->messages($channel)->alert($match);
        $this->pending([$match->id], null);
        $match->forceFill(['delivery_status' => 'pending', 'delivery_note' => null])->syncOriginalAttributes(['delivery_status', 'delivery_note']);

        return $this->queue($channel, 'realtime', $payload, ['match_id' => $match->id] + ($resent ? [
            'summary' => 'Reenvio: '.self::headline($payload),
            'meta' => ['resent' => true],
        ] : []));
    }

    /** @param  Collection<int, AlertMatch>  $matches */
    public function grouped(NotificationChannel $channel, Collection $matches, string $reason): NotificationDelivery
    {
        $this->pending($matches->pluck('id')->all(), 'grouped');

        return $this->queue($channel, 'grouped', $this->messages($channel)->grouped($matches, $reason), [
            'summary' => "+{$matches->count()} alertas agrupados",
            'meta' => ['match_ids' => $matches->pluck('id')->all(), 'reason' => $reason],
        ]);
    }

    public function test(NotificationChannel $channel): NotificationDelivery
    {
        return $this->queue($channel, 'test', $this->messages($channel)->test($channel), ['summary' => 'Mensagem de teste']);
    }

    /**
     * O aviso de que os testes de segurança ligaram e a autorização está pronta
     * (PentestReadyNotice): só o domínio e o link, nunca dado do teste. A Central vale
     * na hora de sair, como nos alertas (o tipo `tests`).
     *
     * @param  list<string>  $domains
     */
    public function testsReady(NotificationChannel $channel, array $domains, string $url, string $switchOn): NotificationDelivery
    {
        return $this->queue($channel, 'tests', $this->messages($channel)->testsReady($domains, $url), [
            'summary' => 'Testes de segurança ativados: '.implode(', ', $domains),
            'meta' => ['pentest_notice' => $switchOn, 'domains' => $domains],
        ]);
    }

    /** Nulo quando o resumo desse dia já existe (o índice único garante um por dia). */
    public function digest(NotificationChannel $channel, Carbon $localDate): ?NotificationDelivery
    {
        $data = $this->digests->build($channel, $localDate);

        try {
            return DB::transaction(fn () => $this->queue($channel, 'digest', $this->messages($channel)->digest($data), [
                'digest_date' => $localDate->toDateString(),
                'summary' => ($data['news'] ? $data['total'].' casos · '.count($data['mine']).' do perfil' : 'só o Radar').(($data['radar'] ?? []) ? ' · Radar: '.count($data['radar']) : ''),
                'meta' => ['total' => $data['total'], 'mine' => count($data['mine'])],
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** Põe (de novo) na fila a entrega de um envio já registrado. */
    public static function dispatch(NotificationDelivery $delivery): void
    {
        $job = match ($delivery->channel?->type) {
            'discord' => new SendDiscordMessage($delivery->id),
            'telegram' => new SendTelegramMessage($delivery->id),
            'webhook' => new SendWebhookMessage($delivery->id),
            default => new SendSlackMessage($delivery->id),
        };

        // O aviso dos testes vai em massa numa ligada: fila própria, depois da dos alertas no mesmo worker (B3).
        dispatch(($delivery->kind === 'tests' ? $job->onQueue('notifications-low') : $job)->afterCommit());
    }

    /** O texto curto de uma mensagem, para o log de envios. */
    public static function headline(array $payload): string
    {
        // Telegram: o texto é a mensagem inteira em HTML; vale a primeira linha.
        if (($payload['parse_mode'] ?? null) === 'HTML') {
            return html_entity_decode(strip_tags(Str::before((string) $payload['text'], "\n")), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Webhook: o título do evento, com a criticidade na frente quando há.
        if (isset($payload['version'], $payload['event'])) {
            $level = $payload['data']['criticity'] ?? null;

            return ($level ? '['.strtoupper($level).'] ' : '').($payload['data']['title'] ?? $payload['event']);
        }

        return (string) ($payload['text'] ?? $payload['embeds'][0]['title'] ?? $payload['content'] ?? '');
    }

    /**
     * Marca os alertas como "na fila". O que já foi entregue por outro canal
     * (Slack e Discord recebem o mesmo alerta) continua "enviado".
     */
    private function pending(array $ids, ?string $note): void
    {
        AlertMatch::query()->whereIn('id', $ids)->where('delivery_status', '!=', 'sent')
            ->update(['delivery_status' => 'pending', 'delivery_note' => $note]);
    }

    private function messages(NotificationChannel $channel): SlackMessages|DiscordMessages|TelegramMessages|WebhookMessages
    {
        return match ($channel->type) {
            'discord' => $this->discord,
            'telegram' => $this->telegram,
            'webhook' => $this->webhook,
            default => $this->slack,
        };
    }

    private function queue(NotificationChannel $channel, string $kind, array $payload, array $extra = []): NotificationDelivery
    {
        $delivery = NotificationDelivery::query()->create([
            'channel_id' => $channel->id,
            'user_id' => $channel->user_id,
            'kind' => $kind,
            'status' => 'queued',
            'payload' => $payload,
            'summary' => Str::limit($extra['summary'] ?? (self::headline($payload) ?: $kind), 250),
        ] + array_intersect_key($extra, array_flip(['match_id', 'digest_date', 'meta'])));

        self::dispatch($delivery->setRelation('channel', $channel));

        return $delivery;
    }
}
