<?php

namespace App\Services\Company;

use App\Models\AlertMatch;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\GithubFinding;
use App\Models\LookalikeAlert;
use App\Models\NotificationDelivery;
use App\Models\SubdomainAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * As travas de saída do plano empresarial (E2e; D7, DESENHO-5.2 §2.2): nada sai nem vale pelo link quando quem recebe
 * já não pode ver o item.
 *
 * - `company_paused`: a empresa de quem recebe está suspensa ou desligada (D7: os envios param, os dados ficam);
 * - `not_visible`: a conta foi desativada pela admin, ou não vê mais o item (saiu do setor, o item mudou de setor).
 *
 * Desde a E5b1 (DESENHO-5.4 §2.5) o item da empresa vai aos canais do escopo dele (Destinations), e quem o criou não
 * importa mais: a trava do item é a empresa ativa, e a do envio já na fila é a empresa ativa e o item ainda cabendo no
 * canal (mudou de setor, a cópia para os gerais foi desligada). O canal pessoal segue a regra de antes: quem recebe é o
 * dono do canal, que tem de ver o item. A conta pessoal passa sempre: ela vê o que criou.
 */
class DeliveryGate
{
    public const PAUSED = 'company_paused';

    public const NOT_VISIBLE = 'not_visible';

    public function __construct(private Visibility $visibility, private Companies $companies, private Destinations $destinations) {}

    /** Por que este envio não sai, ou nulo se sai. */
    public function forDelivery(NotificationDelivery $delivery): ?string
    {
        $channel = $delivery->channel;

        if ($channel?->isCompany()) {
            if (! $this->companyActive((int) $channel->company_id)) {
                return self::PAUSED;
            }

            foreach ($this->itemsOf($delivery) as $item) {
                if (! $this->destinations->reaches($channel, $item)) {
                    return self::NOT_VISIBLE;
                }
            }

            return null;
        }

        $recipient = $channel?->user;

        return $recipient === null ? null : $this->refusal($recipient, $this->itemsOf($delivery));
    }

    /** Por que o aviso deste item não sai agora (na decisão do roteador), ou nulo se sai: a empresa do item ativa (D7). */
    public function forItem(Model $item): ?string
    {
        $company = $this->visibility->rootOf($item)?->getAttribute('company_id');

        return $company === null || $this->companyActive((int) $company) ? null : self::PAUSED;
    }

    /**
     * Por que o link da mensagem deste item não vale mais, ou nulo se vale. Item da empresa: a empresa ativa (o link é a
     * credencial de quem lê o canal; quem criou não importa). Item pessoal: quem criou ainda o vê.
     */
    public function forLink(Model $item): ?string
    {
        $root = $this->visibility->rootOf($item);

        if ($root?->getAttribute('company_id') !== null) {
            return $this->forItem($root);
        }

        $creator = $root?->user;

        return $creator === null ? self::NOT_VISIBLE : $this->refusal($creator, [$item]);
    }

    private function companyActive(int $companyId): bool
    {
        $company = Company::query()->find($companyId);

        return $company !== null && $this->companies->isActive($company);
    }

    /** @param  list<Model>  $items */
    public function refusal(User $recipient, array $items): ?string
    {
        $viewer = $this->visibility->viewer($recipient);

        if ($viewer->kind === Viewer::NOTHING) {
            return CompanyMember::query()->where('user_id', $recipient->id)->whereNotNull('deactivated_at')->exists() ? self::NOT_VISIBLE : self::PAUSED;
        }

        foreach ($items as $item) {
            if (! $this->visibility->sees($recipient, $item)) {
                return self::NOT_VISIBLE;
            }
        }

        return null;
    }

    /** Os itens que a entrega leva: os alertas de notícia, o aviso do Radar (de um parecido ou de um subdomínio) ou o achado do GitHub. O resumo diário não tem item: vale só a conta. @return list<Model> */
    private function itemsOf(NotificationDelivery $delivery): array
    {
        $meta = $delivery->meta ?? [];

        return match (true) {
            isset($meta['lookalike_alert_id']) => LookalikeAlert::query()->whereKey($meta['lookalike_alert_id'])->get()->all(),
            isset($meta['subdomain_alert_id']) => SubdomainAlert::query()->whereKey($meta['subdomain_alert_id'])->get()->all(),
            isset($meta['github_finding_id']) => GithubFinding::query()->whereKey($meta['github_finding_id'])->get()->all(),
            default => AlertMatch::query()->whereKey(array_values(array_filter(array_merge([$delivery->match_id], $meta['match_ids'] ?? []))))->get()->all(),
        };
    }
}
