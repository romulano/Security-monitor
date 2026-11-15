<?php

namespace App\Services\Lookalike;

use App\Models\LookalikeAlert;
use App\Models\LookalikeWatch;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Services\Alerts\AlertRouter;
use App\Services\Alerts\Notifier;
use App\Services\Company\DeliveryGate;
use App\Services\Company\Destinations;
use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * Para onde vai um aviso do Radar, com as mesmas regras de canal dos alertas
 * de notícia: canal quebrado, pausado, com o Radar desligado na Central ou
 * com "na hora" desligado não recebe; horário silencioso e limite por hora
 * seguram o que não é Crítico. O que não sai na hora aparece no resumo diário
 * (seção Radar de domínios) dos canais que recebem o Radar.
 */
class LookalikeAlertRouter
{
    public function __construct(private AlertRouter $alerts, private AlertMessages $messages, private DeliveryGate $gate, private Destinations $destinations) {}

    public function route(LookalikeAlert $alert): void
    {
        $lookalike = $alert->lookalike;
        $watch = $lookalike->watch;
        // Radar de empresa suspensa (D7): nada sai. O SendChannelMessage confere de novo na saída.
        if ($refusal = $this->gate->forItem($alert)) {
            $alert->forceFill(['status' => 'skipped', 'note' => $refusal])->save();

            return;
        }

        [$ready, $skip] = $this->channels($watch, $alert->risk);

        if ($ready === []) {
            $alert->forceFill(['status' => 'skipped', 'note' => $skip])->save();

            return;
        }

        // "Na fila" antes de enfileirar: o envio (às vezes imediato) é quem marca "enviado".
        $alert->forceFill(['status' => 'queued', 'note' => null])->save();

        foreach ($ready as $channel) {
            $this->send($alert, $channel);
        }
    }

    /**
     * Os canais do Radar (os de quem o criou, na conta pessoal; os do escopo dele, na empresa, E5b1) que recebem um
     * aviso deste risco agora, ou o motivo de nenhum receber. Também serve ao aviso de subdomínio (SubdomainAlertRouter).
     *
     * @return array{0: list<NotificationChannel>, 1: ?string}
     */
    public function channels(LookalikeWatch $watch, string $risk): array
    {
        $channels = $this->destinations->for($watch);

        if (! $watch->isActive() || $channels->isEmpty()) {
            return [[], $channels->isEmpty() ? 'no_channel' : 'paused'];
        }

        $ready = [];
        $skips = [];

        foreach ($channels as $channel) {
            $skip = $channel->skipReason('radar')
                ?? (! Settings::bool('alerts.realtime_enabled') || ! $channel->realtime ? 'realtime_off' : null)
                // Crítico fura o silêncio; o resto fica para o resumo.
                ?? ($risk !== 'critical' && $channel->quietUntil() ? 'quiet_hours' : null)
                ?? ($this->alerts->overLimit($channel) ? 'throttled' : null);

            if ($skip) {
                $skips[] = $skip;
            } else {
                $ready[] = $channel;
            }
        }

        return [$ready, $ready !== [] ? null
            : (collect(['quiet_hours', 'throttled', ...AlertRouter::SKIP_NOTES])->first(fn ($n) => in_array($n, $skips, true)) ?? 'no_channel')];
    }

    /** Registra o envio (o admin vê e reenvia em Entregas) e põe na fila `notifications`. */
    public function send(LookalikeAlert $alert, NotificationChannel $channel): NotificationDelivery
    {
        $payload = $this->messages->alert($alert, $channel);

        $delivery = NotificationDelivery::query()->create([
            'channel_id' => $channel->id,
            'user_id' => $channel->user_id,
            'kind' => 'realtime',
            'status' => 'queued',
            'payload' => $payload,
            'summary' => Str::limit('Radar: '.$alert->lookalike->host.' ('.$alert->lookalike->riskLabel().')', 250),
            'meta' => ['lookalike_alert_id' => $alert->id],
        ]);

        Notifier::dispatch($delivery->setRelation('channel', $channel));

        return $delivery;
    }
}
