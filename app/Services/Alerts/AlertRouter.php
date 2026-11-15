<?php

namespace App\Services\Alerts;

use App\Models\AlertMatch;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Services\Company\DeliveryGate;
use App\Services\Company\Destinations;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * O que fazer com um alerta em cada canal do cliente (Slack, Discord,
 * Telegram): mandar agora, segurar (horário silencioso ou limite por hora) ou
 * deixar só para o resumo diário. Canal pausado ou com as notícias desligadas
 * na Central não entra na conta. Basta um canal poder receber agora para o
 * alerta sair; um canal em silêncio nessa hora recebe o caso só no resumo dele.
 */
class AlertRouter
{
    /** Notas de "não enviado", da que mais explica para a que menos explica. */
    public const SKIP_NOTES = ['realtime_off', 'channel_paused', 'channel_broken', 'type_off'];

    public function __construct(private Notifier $notifier) {}

    /**
     * Dois alertas do mesmo cliente (produtos diferentes) na mesma notícia:
     * uma mensagem só, pelo de maior pontuação, citando os outros. Cada
     * alerta guarda o seu registro, para o histórico e o feedback.
     *
     * @param  Collection<int, AlertMatch>  $matches
     */
    public function routeGroup(Collection $matches): void
    {
        $matches = $this->withoutHidden($matches);

        if ($matches->isEmpty()) {
            return;
        }

        $primary = $matches->sortByDesc('score')->first();

        foreach ($matches as $match) {
            if ($match->isNot($primary)) {
                $match->forceFill(['delivery_status' => 'skipped', 'delivery_note' => 'merged'])->save();
            }
        }

        $this->route($primary);
    }

    public function route(AlertMatch $match): void
    {
        $profile = $match->profile;
        // Pessoal: os canais de quem criou. Empresa (E5b1): os do escopo do alerta — o setor (e os gerais, com a cópia)
        // ou os gerais —, saia ou não quem criou do setor. Empresa suspensa não manda nada; a fila confere de novo (E2e).
        $channels = app(Destinations::class)->for($match);

        if ($refusal = app(DeliveryGate::class)->forItem($match)) {
            $match->forceFill(['delivery_status' => 'skipped', 'delivery_note' => $refusal])->save();

            return;
        }

        if ($profile->isPaused() || $channels->isEmpty()) {
            $match->forceFill(['delivery_status' => 'skipped', 'delivery_note' => $profile->isPaused() ? 'paused' : 'no_channel'])->save();

            return;
        }

        $ready = [];
        $holds = [];
        $skips = [];

        foreach ($channels as $channel) {
            $skip = $channel->skipReason('news')
                ?? (! Settings::bool('alerts.realtime_enabled') || ! $channel->realtime ? 'realtime_off' : null);

            if ($skip) {
                $skips[] = $skip;
            } elseif ($match->criticity !== 'critical' && ($until = $channel->quietUntil())) {
                // Critical fura o silêncio; o resto espera o fim dele.
                $holds[] = ['quiet_hours', $until];
            } elseif ($this->overLimit($channel)) {
                $holds[] = ['throttled', now()->addMinutes(15)];
            } else {
                $ready[] = $channel;
            }
        }

        if ($ready !== []) {
            foreach ($ready as $channel) {
                $this->notifier->alert($match, $channel);
            }

            return;
        }

        if ($holds !== []) {
            [$note, $until] = collect($holds)->sortBy(fn (array $hold) => $hold[1]->getTimestamp())->first();
            $match->forceFill(['delivery_status' => 'held', 'delivery_note' => $note, 'release_at' => $until])->save();

            return;
        }

        $match->forceFill(['delivery_status' => 'skipped', 'delivery_note' => self::skipNote($skips)])->save();
    }

    /**
     * Alerta de matéria escondida do feed público (fora de segurança) não vira
     * mensagem: fica "não enviado", com o motivo. A matéria pode ter saído do
     * feed enquanto o alerta esperava a IA ou o fim do horário silencioso.
     *
     * @param  Collection<int, AlertMatch>  $matches
     * @return Collection<int, AlertMatch> os que seguem
     */
    public function withoutHidden(Collection $matches): Collection
    {
        [$hidden, $visible] = $matches->partition(fn (AlertMatch $match) => (bool) $match->case?->isHidden());

        if ($hidden->isNotEmpty()) {
            AlertMatch::query()->whereIn('id', $hidden->pluck('id')->all())
                ->update(['delivery_status' => 'skipped', 'delivery_note' => 'hidden', 'release_at' => null]);
        }

        return $visible->values();
    }

    /**
     * A nota que melhor explica por que nenhum canal recebeu: ir para o resumo
     * vale mais que envio pausado, que vale mais que canal quebrado, que vale
     * mais que tipo desligado na Central, que vale mais que "sem canal".
     */
    public static function skipNote(array $reasons): string
    {
        foreach (self::SKIP_NOTES as $note) {
            if (in_array($note, $reasons, true)) {
                return $note;
            }
        }

        return 'no_channel';
    }

    public function overLimit(NotificationChannel $channel): bool
    {
        return NotificationDelivery::query()
            ->where('channel_id', $channel->id)
            ->where('kind', 'realtime')
            ->whereIn('status', ['queued', 'sent'])
            ->where('created_at', '>=', now()->subHour())
            ->count() >= Settings::int('alerts.max_per_hour');
    }

    /** Menor de vários instantes (fim do silêncio de cada canal). */
    public static function earliest(array $moments): ?Carbon
    {
        return collect($moments)->sortBy(fn (Carbon $m) => $m->getTimestamp())->first();
    }
}
