<?php

namespace App\Services\Access;

use App\Models\AlertMatch;
use App\Models\GithubFinding;
use App\Models\LookalikeAlert;
use App\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Model;

/**
 * Por que um aviso não sai, ou o link da mensagem não vale mais. Sem empresa sobra uma regra só: o item tem de
 * continuar sendo da conta que recebe (o item apagado, ou cujo dono mudou, não é mais visível).
 */
class DeliveryGate
{
    public const NOT_VISIBLE = 'not_visible';

    public function __construct(private Visibility $visibility) {}

    /** Por que esta entrega não sai agora, ou nulo se sai. */
    public function forDelivery(NotificationDelivery $delivery): ?string
    {
        $recipient = $delivery->channel?->user;

        if ($recipient === null) {
            return null;
        }

        foreach ($this->itemsOf($delivery) as $item) {
            if (! $this->visibility->sees($recipient, $item)) {
                return self::NOT_VISIBLE;
            }
        }

        return null;
    }

    /** Por que o aviso deste item não sai agora (na decisão do roteador), ou nulo se sai. */
    public function forItem(Model $item): ?string
    {
        return null;
    }

    /** Por que o link da mensagem deste item não vale mais, ou nulo se vale: quem criou ainda tem de vê-lo. */
    public function forLink(Model $item): ?string
    {
        $creator = $this->visibility->rootOf($item)?->user;

        return $creator === null || ! $this->visibility->sees($creator, $item) ? self::NOT_VISIBLE : null;
    }

    /** Os itens que esta entrega levou: a imitação do Radar, o achado do GitHub ou os matches do alerta.
     *
     * @return list<Model>
     */
    private function itemsOf(NotificationDelivery $delivery): array
    {
        $meta = $delivery->meta ?? [];

        return match (true) {
            isset($meta['lookalike_alert_id']) => LookalikeAlert::query()->whereKey($meta['lookalike_alert_id'])->get()->all(),
            isset($meta['github_finding_id']) => GithubFinding::query()->whereKey($meta['github_finding_id'])->get()->all(),
            default => AlertMatch::query()->whereKey(array_values(array_filter(array_merge([$delivery->match_id], $meta['match_ids'] ?? []))))->get()->all(),
        };
    }
}
