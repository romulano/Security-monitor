<?php

namespace Tests\Support;

use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Services\Alerts\Notifier;
use RuntimeException;

/** O Notifier que falha no aviso dos testes de uma conta só: o resto do job tem de seguir (B2 do Red Team no aviso). */
class FailingNotifier extends Notifier
{
    public ?int $poison = null;

    public function testsReady(NotificationChannel $channel, array $domains, string $url, string $switchOn): NotificationDelivery
    {
        $delivery = parent::testsReady($channel, $domains, $url, $switchOn);

        if ($channel->user_id === $this->poison) {
            throw new RuntimeException('falha só desta conta');
        }

        return $delivery;
    }
}
