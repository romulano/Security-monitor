<?php

namespace App\Services\Alerts\Discovery;

use App\Models\MonitoredDomain;
use App\Models\TechCatalog;
use Illuminate\Support\Collection;

/**
 * Sugestões pelo DNS público no passo do domínio: o MX, o SPF, os outros TXT,
 * o NS e o CNAME do www dizem muito da stack (Zoho no MX, SendGrid e Zendesk
 * no SPF). São dados que qualquer pessoa consulta: ler não faz nenhuma
 * requisição ao site e por isso não espera o aceite nem a prova de posse,
 * que continuam valendo para a descoberta. Nada entra no perfil sozinho: o
 * cliente marca o que quer adicionar.
 *
 * Cada sugestão guarda a decisão (pending, added, dismissed), para a que foi
 * dispensada não voltar na leitura seguinte.
 */
class DnsSuggester
{
    /** Regras do catálogo que olham só o DNS. */
    public const TYPES = ['mx', 'spf', 'txt', 'ns', 'cname'];

    /** O DNS muda pouco: ao salvar o passo, relido no máximo 1 vez por dia. */
    public const REFRESH_HOURS = 24;

    public function __construct(
        private DnsResolver $dns,
        private SignalExtractor $extractor,
        private StackDetector $detector,
    ) {}

    /**
     * Lê o DNS do domínio (se nunca leu ou a leitura tem mais de um dia) e
     * acrescenta como pendente o que o catálogo reconhecer e não estiver em
     * `$skip` (slugs que o perfil já tem).
     */
    public function refresh(MonitoredDomain $domain, array $skip): void
    {
        if ($domain->dns_checked_at?->gt(now()->subHours(self::REFRESH_HOURS))) {
            return;
        }

        $host = $domain->domain;
        $seen = $this->extractor->seen($host, [], $this->dns->mx($host), $this->dns->txt($host), $this->dns->ns($host), $this->dns->cname('www.'.$host));

        // Decididas ficam; pendentes são refeitas com o que o DNS mostra agora.
        $items = collect($domain->dns_suggestions ?? [])->reject(fn (array $s) => $s['status'] === 'pending')->keyBy('slug')->all();

        foreach ($this->detector->detect($seen, $this->catalog()) as $found) {
            $slug = $found['tech']->slug;

            if (! isset($items[$slug]) && ! in_array($slug, $skip, true)) {
                $items[$slug] = ['slug' => $slug, 'name' => $found['tech']->name, 'evidence' => $found['evidence'], 'status' => 'pending'];
            }
        }

        $domain->forceFill(['dns_suggestions' => array_values($items), 'dns_checked_at' => now()])->save();
    }

    /** @return list<array{slug: string, name: string, evidence: string, status: string}> */
    public function pending(MonitoredDomain $domain): array
    {
        return array_values(array_filter($domain->dns_suggestions ?? [], fn (array $s) => $s['status'] === 'pending'));
    }

    /** As pendentes em `$added` viram adicionadas; as outras pendentes, dispensadas. */
    public function decide(MonitoredDomain $domain, array $added): void
    {
        $domain->forceFill(['dns_suggestions' => array_map(
            fn (array $s) => $s['status'] === 'pending' ? ['status' => in_array($s['slug'], $added, true) ? 'added' : 'dismissed'] + $s : $s,
            $domain->dns_suggestions ?? [],
        )])->save();
    }

    /**
     * Só o catálogo curado (o que a descoberta cadastrou veio do site de outro
     * cliente) e, de cada item, só as regras de DNS.
     *
     * @return Collection<int, TechCatalog>
     */
    private function catalog(): Collection
    {
        return TechCatalog::query()->active()->where('origin', '!=', 'discovery')->get()
            ->map(function (TechCatalog $tech) {
                $copy = clone $tech;
                $copy->detect = array_values(array_filter($tech->detect ?? [], fn (array $rule) => in_array($rule['type'] ?? null, self::TYPES, true)));

                return $copy;
            })
            ->filter(fn (TechCatalog $tech) => $tech->detect !== []);
    }
}
