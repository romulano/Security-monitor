<?php

namespace App\Services\Lookalike;

use App\Models\NotificationDelivery;
use App\Models\SubdomainAlert;
use App\Services\Alerts\Notifier;
use App\Services\Company\DeliveryGate;
use Illuminate\Support\Str;

/**
 * Para onde vai um aviso de subdomínio (E4a): pelos canais do Radar (os do escopo dele, na empresa, E5b1), com as regras
 * de canal do aviso do Radar (LookalikeAlertRouter::channels: Central, "na hora", silêncio, limite por hora) e a trava
 * da empresa suspensa (DeliveryGate). A tomada é Crítico e fura o silêncio.
 */
class SubdomainAlertRouter
{
    public function __construct(private LookalikeAlertRouter $radar, private SubdomainAlertMessages $messages, private DeliveryGate $gate) {}

    public function route(SubdomainAlert $alert): void
    {
        $watch = $alert->watch;

        if ($refusal = $this->gate->forItem($alert)) {
            $alert->forceFill(['status' => 'skipped', 'note' => $refusal])->save();

            return;
        }

        [$ready, $skip] = $this->radar->channels($watch, $alert->risk);

        if ($ready === []) {
            $alert->forceFill(['status' => 'skipped', 'note' => $skip])->save();

            return;
        }

        $alert->forceFill(['status' => 'queued', 'note' => null])->save();

        foreach ($ready as $channel) {
            $delivery = NotificationDelivery::query()->create([
                'channel_id' => $channel->id,
                'user_id' => $channel->user_id,
                'kind' => 'realtime',
                'status' => 'queued',
                'payload' => $this->messages->alert($alert, $channel),
                'summary' => Str::limit('Radar: '.$alert->subdomain->name.' ('.SubdomainAlert::KINDS[$alert->kind].')', 250),
                'meta' => ['subdomain_alert_id' => $alert->id],
            ]);

            Notifier::dispatch($delivery->setRelation('channel', $channel));
        }
    }
}
