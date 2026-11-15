<?php

namespace App\Console\Commands;

use App\Models\AlertMatch;
use App\Services\Alerts\AlertRouter;
use App\Services\Alerts\Notifier;
use App\Services\Company\DeliveryGate;
use App\Services\Company\Destinations;
use Illuminate\Console\Command;

/**
 * Solta os alertas segurados (horário silencioso ou limite por hora) cujo
 * prazo venceu, em cada canal do cliente que pode receber agora: um só vai
 * como alerta normal, vários viram uma mensagem agrupada. Roda a cada 5
 * minutos.
 */
class AlertsFlushCommand extends Command
{
    protected $signature = 'secmon:alerts-flush';

    protected $description = 'Envia os alertas segurados cujo horário chegou';

    public function handle(Notifier $notifier, AlertRouter $router, DeliveryGate $gate, Destinations $destinations): int
    {
        $groups = AlertMatch::query()
            ->where('delivery_status', 'held')
            ->where('release_at', '<=', now())
            ->with(['profile.user', 'case', 'article'])
            ->orderBy('matched_at')
            ->get()
            ->groupBy('profile_id');

        $messages = 0;

        foreach ($groups as $matches) {
            // A matéria pode ter saído do feed público enquanto o alerta estava segurado.
            if (($matches = $router->withoutHidden($matches))->isEmpty()) {
                continue;
            }

            $profile = $matches->first()->profile;
            // Os canais do escopo do alerta (E5b1); segurado e, enquanto isso, a empresa foi suspensa: não sai.
            $channels = $destinations->for($profile);
            $ids = $matches->pluck('id');

            if ($refusal = $gate->forItem($profile)) {
                AlertMatch::query()->whereIn('id', $ids)->update(['delivery_status' => 'skipped', 'delivery_note' => $refusal, 'release_at' => null]);

                continue;
            }

            if (! $profile->isActive() || $channels->isEmpty()) {
                AlertMatch::query()->whereIn('id', $ids)
                    ->update(['delivery_status' => 'skipped', 'delivery_note' => $profile->isActive() ? 'no_channel' : 'paused', 'release_at' => null]);

                continue;
            }

            $ready = [];
            $quiet = [];
            $skips = [];

            foreach ($channels as $channel) {
                // Pausa e notícias desligadas na Central valem também para o que já estava segurado.
                if ($skip = $channel->skipReason('news') ?? (! $channel->realtime ? 'realtime_off' : null)) {
                    $skips[] = $skip;
                } elseif ($until = $channel->quietUntil()) {
                    // O silêncio pode ter sido estendido depois que o alerta foi segurado.
                    $quiet[] = $until;
                } else {
                    $ready[] = $channel;
                }
            }

            if ($ready === []) {
                if ($quiet !== []) {
                    AlertMatch::query()->whereIn('id', $ids)->update(['release_at' => AlertRouter::earliest($quiet)]);
                } else {
                    AlertMatch::query()->whereIn('id', $ids)
                        ->update(['delivery_status' => 'skipped', 'delivery_note' => AlertRouter::skipNote($skips), 'release_at' => null]);
                }

                continue;
            }

            foreach ($ready as $channel) {
                if ($matches->count() === 1 && ! $router->overLimit($channel)) {
                    $notifier->alert($matches->first(), $channel);
                } else {
                    $notifier->grouped($channel, $matches, (string) $matches->first()->delivery_note);
                }

                $messages++;
            }
        }

        $this->info("{$messages} mensagem(ns) enfileirada(s).");

        return self::SUCCESS;
    }
}
