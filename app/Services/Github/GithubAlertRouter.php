<?php

namespace App\Services\Github;

use App\Models\GithubFinding;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Services\Alerts\AlertRouter;
use App\Services\Alerts\Notifier;
use App\Services\Access\DeliveryGate;
use App\Services\Access\Destinations;
use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * Para onde vai um achado do GitHub aprovado pelas duas etapas da IA, com as
 * regras de canal dos alertas: app quebrado, pausado, com o GitHub desligado
 * na Central ou com "na hora" desligado não recebe; horário silencioso e
 * limite por hora seguram o que não é Critical. O que não sai na hora aparece
 * no resumo diário (seção GitHub) dos apps que recebem o GitHub.
 */
class GithubAlertRouter
{
    public function __construct(private AlertRouter $alerts, private GithubMessages $messages, private DeliveryGate $gate, private Destinations $destinations) {}

    public function route(GithubFinding $finding): void
    {
        $monitor = $finding->monitor;
        // Monitoramento de empresa suspensa (D7): nada sai. Os canais são os do escopo dele (E5b1), não os de quem criou;
        // o SendChannelMessage confere de novo.
        if ($refusal = $this->gate->forItem($finding)) {
            $finding->forceFill(['status' => 'skipped', 'note' => $refusal])->save();

            return;
        }

        $channels = $this->destinations->for($monitor);

        if (! $monitor->isActive() || $channels->isEmpty()) {
            $finding->forceFill(['status' => 'skipped', 'note' => $channels->isEmpty() ? 'no_channel' : 'paused'])->save();

            return;
        }

        $ready = [];
        $skips = [];

        foreach ($channels as $channel) {
            $skip = $channel->skipReason('github')
                ?? (! Settings::bool('alerts.realtime_enabled') || ! $channel->realtime ? 'realtime_off' : null)
                // Critical fura o silêncio; o resto fica para o resumo.
                ?? ($finding->level() !== 'critical' && $channel->quietUntil() ? 'quiet_hours' : null)
                ?? ($this->alerts->overLimit($channel) ? 'throttled' : null);

            if ($skip) {
                $skips[] = $skip;
            } else {
                $ready[] = $channel;
            }
        }

        if ($ready === []) {
            $finding->forceFill(['status' => 'skipped', 'note' => collect(['quiet_hours', 'throttled', ...AlertRouter::SKIP_NOTES])->first(fn ($n) => in_array($n, $skips, true)) ?? 'no_channel'])->save();

            return;
        }

        // "Na fila" antes de enfileirar: o envio (às vezes imediato) é quem marca "enviado".
        $finding->forceFill(['status' => 'queued', 'note' => null])->save();

        foreach ($ready as $channel) {
            $this->send($finding, $channel);
        }
    }

    /** Registra o envio (o admin vê e reenvia em Entregas) e põe na fila `notifications`. */
    public function send(GithubFinding $finding, NotificationChannel $channel, bool $resent = false): NotificationDelivery
    {
        $delivery = NotificationDelivery::query()->create([
            'channel_id' => $channel->id,
            'user_id' => $channel->user_id,
            'kind' => 'realtime',
            'status' => 'queued',
            'payload' => $this->messages->alert($finding, $channel),
            'summary' => Str::limit('GitHub: '.$finding->location().' ('.mb_strtoupper($finding->level()).')', 250),
            'meta' => array_filter(['github_finding_id' => $finding->id, 'resent' => $resent]),
        ]);

        Notifier::dispatch($delivery->setRelation('channel', $channel));

        return $delivery;
    }
}
