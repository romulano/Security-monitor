<?php

namespace App\Services\Alerts;

use App\Models\AlertProfile;
use App\Models\CustomAnswerTerm;
use App\Models\InterviewQuestion;
use App\Models\MonitoredDomain;

/**
 * Transforma o perfil em termos e em queries do Elasticsearch.
 *
 * - terms(): o que o MatchEngine procura em cada caso (com o rótulo que vai
 *   para o "por que você recebeu");
 * - matchQuery(): vai para o percolator. Sem filtro de nível — o nível é
 *   aplicado depois, para os casos abaixo do mínimo ficarem registrados como
 *   suprimidos e o admin poder calibrar;
 * - compiledQuery(): o mesmo com o nível, na sintaxe da barra de busca. É a
 *   que aparece na revisão, na prévia de 30 dias e em "testar no feed".
 */
class ProfileCompiler
{
    /** @var array<string, array>|null */
    private ?array $optionCache = null;

    /** @var array<string, list<array{0: string, 1: string, 2: ?CustomAnswerTerm}>> */
    private array $customCache = [];

    /**
     * @return array{
     *   stack: array<string, string>,
     *   strict: array<string, string>,
     *   brand: array<string, string>,
     *   sector: array<string, string>,
     *   regions: list<string>,
     *   attack: list<string>
     * } termo => rótulo; `strict` só vale em produtos afetados e fornecedores;
     *   `attack` são os tipos de ameaça escritos pelo cliente ("Outro")
     */
    public function terms(AlertProfile $profile): array
    {
        $profile->loadMissing(['techs', 'terms', 'domains']);

        $stack = [];
        $strict = [];

        foreach ($profile->techs as $tech) {
            if (! $tech->active) {
                continue;
            }

            foreach ($tech->terms ?? [] as $term) {
                if ($tech->strict) {
                    $strict[$this->clean($term)] ??= $tech->name;
                } else {
                    $stack[$this->clean($term)] ??= $tech->name;
                }
            }
        }

        foreach ($profile->terms as $term) {
            $stack[$this->clean($term->value)] ??= $term->value;
        }

        $brand = [];

        foreach (array_merge([$profile->company_name], $profile->brands ?? [], $profile->domains->pluck('domain')->all()) as $name) {
            if (filled($name)) {
                $brand[$this->clean($name)] ??= trim($name);
            }
        }

        $sector = [];

        foreach ($profile->sectors ?? [] as $value) {
            $option = $this->option('sectors', $value);

            foreach ($option['terms'] ?? [] as $term) {
                $sector[$this->clean($term)] ??= $option['label'];
            }
        }

        $regions = [];

        foreach ($profile->regions ?? [] as $value) {
            $regions = array_merge($regions, $this->option('regions', $value)['codes'] ?? []);
        }

        // Respostas escritas ("Outro"): a própria resposta vale como termo, e os
        // termos em inglês que a IA gerou entram quando ficam prontos.
        $attack = [];

        foreach ($this->customAnswers($profile) as [$kind, $label, $row]) {
            if ($kind === 'region') {
                $regions = array_merge($regions, $row?->codes ?? []);

                continue;
            }

            foreach (array_merge([$label], $row?->terms ?? []) as $term) {
                switch ($kind) {
                    case 'sector':
                        $sector[$this->clean($term)] ??= $label;
                        break;
                    case 'attack_type':
                        $attack[] = $this->clean($term);
                        break;
                    default:
                        $stack[$this->clean($term)] ??= $label;
                }
            }
        }

        return [
            'stack' => array_filter($stack, fn ($label, $term) => $term !== '', ARRAY_FILTER_USE_BOTH),
            // Um termo que também está numa tecnologia comum vale no texto.
            'strict' => array_diff_key($strict, $stack),
            'brand' => $profile->include_brand_mentions ? $brand : [],
            'sector' => $profile->include_sector_news ? $sector : [],
            'regions' => array_values(array_unique($regions)),
            'attack' => array_values(array_unique(array_filter($attack, fn ($t) => trim($t, ' *') !== ''))),
        ];
    }

    /**
     * Respostas escritas do perfil, com a linha de termos de cada uma (null se
     * ainda não registrada).
     *
     * @return list<array{0: string, 1: string, 2: ?CustomAnswerTerm}> [kind, rótulo, termos]
     */
    public function customAnswers(AlertProfile $profile): array
    {
        $answers = (array) ($profile->custom_answers ?? []);

        if ($answers === []) {
            return [];
        }

        $key = $profile->id.':'.md5(json_encode($answers));

        if (isset($this->customCache[$key])) {
            return $this->customCache[$key];
        }

        $wanted = [];

        foreach ($answers as $questionKey => $labels) {
            foreach ((array) $labels as $label) {
                $label = trim((string) $label);

                if ($label !== '') {
                    $wanted[] = [CustomAnswerTerm::kindFor((string) $questionKey), $label, CustomAnswerTerm::normalize($label)];
                }
            }
        }

        $rows = CustomAnswerTerm::query()
            ->whereIn('normalized', array_unique(array_column($wanted, 2)))
            ->get()
            ->keyBy(fn (CustomAnswerTerm $t) => $t->kind.'|'.$t->normalized);

        return $this->customCache[$key] = array_map(
            fn ($w) => [$w[0], $w[1], $rows->get($w[0].'|'.$w[2])],
            $wanted,
        );
    }

    /** Query do percolator: superconjunto do que o MatchEngine aceita. */
    public function matchQuery(AlertProfile $profile): ?string
    {
        $terms = $this->terms($profile);
        $clauses = array_filter([
            $this->phrases('all', array_keys($terms['stack'] + $terms['strict'])),
            $this->phrases('all', array_keys($terms['brand'])),
            $this->keywords('victim_sectors', array_keys($terms['sector']), leading: true),
        ]);

        return $clauses === [] ? null : implode(' OR ', $clauses);
    }

    /** Query legível, com nível, que funciona colada na busca do feed. */
    public function compiledQuery(AlertProfile $profile): ?string
    {
        $terms = $this->terms($profile);

        $interest = array_filter([
            $this->phrases('all', array_keys($terms['stack'])),
            // Os amplos só valem como produto afetado ou fornecedor; no feed o
            // mais próximo disso é o prefixo nesses campos.
            $this->keywords('affected_products', array_map(fn ($t) => rtrim($t, '*').'*', array_keys($terms['strict'])), leading: false),
            $this->keywords('vendors', array_map(fn ($t) => rtrim($t, '*').'*', array_keys($terms['strict'])), leading: false),
            $this->keywords('victim_sectors', array_keys($terms['sector']), leading: false),
        ]);

        $parts = [];

        if ($interest !== []) {
            $interest = implode("\n    OR ", $interest);
            $levels = $profile->allowedLevels();

            $parts[] = count($levels) === count(AlertProfile::LEVELS)
                ? "( {$interest} )"
                : '( criticity:('.implode(' OR ', $levels).") AND (\n    {$interest}\n) )";
        }

        if ($brand = $this->phrases('all', array_keys($terms['brand']))) {
            $parts[] = $brand;
        }

        return $parts === [] ? null : implode("\nOR ", $parts);
    }

    /** Opção de uma pergunta de sistema (setores, regiões, tipos de ataque). */
    public function option(string $questionKey, string $value): ?array
    {
        if ($this->optionCache === null) {
            $this->optionCache = InterviewQuestion::query()
                ->whereIn('key', ['sectors', 'regions', 'attack_types'])
                ->get()
                ->mapWithKeys(fn (InterviewQuestion $q) => [$q->key => collect($q->options)->keyBy('value')->all()])
                ->all();
        }

        return $this->optionCache[$questionKey][$value] ?? null;
    }

    public static function domainOf(AlertProfile $profile): ?MonitoredDomain
    {
        return $profile->domains->first();
    }

    private function phrases(string $field, array $terms): ?string
    {
        $terms = array_values(array_filter($terms, fn ($t) => trim($t, ' *') !== ''));

        if ($terms === []) {
            return null;
        }

        // No campo `all` (texto analisado) o termo vira frase; o * de prefixo
        // não existe em frase, então sai — o MatchEngine continua exigindo-o.
        $quoted = array_map(fn ($t) => '"'.addcslashes(rtrim($t, '*'), '"\\').'"', $terms);

        return $field.':('.implode(' OR ', array_unique($quoted)).')';
    }

    /**
     * Campos de indicador são um token só, sem caixa ("financial services"):
     * espaço e caracteres reservados são escapados e o * vira prefixo. Com
     * $leading, cerca de * dos dois lados (superconjunto para o percolator).
     */
    private function keywords(string $field, array $terms, bool $leading): ?string
    {
        $terms = array_values(array_filter($terms, fn ($t) => trim($t, ' *') !== ''));

        if ($terms === []) {
            return null;
        }

        $escaped = array_map(function (string $term) use ($leading) {
            $body = preg_replace('/([+\-=&|><!(){}\[\]^"~*?:\\\\\/\s])/', '\\\\$1', rtrim($term, '*'));

            return ($leading ? '*' : '').$body.($leading || str_ends_with($term, '*') ? '*' : '');
        }, $terms);

        return $field.':('.implode(' OR ', array_unique($escaped)).')';
    }

    private function clean(string $term): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($term)));
    }
}
