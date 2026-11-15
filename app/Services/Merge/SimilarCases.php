<?php

namespace App\Services\Merge;

use App\Models\NewsCase;
use App\Support\Markdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Acha as matérias que parecem contar a mesma história. É só a peneira: o
 * grupo vai para a IA, que dá a palavra final antes de qualquer fusão.
 *
 * O agrupamento da coleta compara só títulos e CVEs. Aqui entra o texto todo:
 * cosseno TF-IDF de título (peso dobrado), resumo e indicadores específicos
 * (CVE, produto, malware, grupo, vítima). As matérias novas da execução são
 * comparadas com todas da janela por um índice invertido, sem varrer pares.
 */
class SimilarCases
{
    private const STOPWORDS = [
        'about', 'after', 'against', 'all', 'also', 'and', 'any', 'are', 'article', 'because', 'been', 'before',
        'being', 'between', 'both', 'but', 'can', 'could', 'did', 'does', 'during', 'each', 'either', 'for',
        'from', 'further', 'had', 'has', 'have', 'having', 'here', 'how', 'into', 'its', 'itself', 'may',
        'might', 'more', 'most', 'must', 'new', 'not', 'now', 'only', 'other', 'our', 'out', 'over', 'own',
        'per', 'said', 'same', 'says', 'should', 'since', 'some', 'such', 'than', 'that', 'the', 'their',
        'them', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'under', 'until', 'upon',
        'using', 'very', 'via', 'was', 'were', 'what', 'when', 'where', 'whether', 'which', 'while', 'who',
        'whom', 'why', 'will', 'with', 'within', 'without', 'would', 'yet', 'you', 'your', 'technical',
        'details', 'remediation', 'reported', 'reports', 'report', 'source', 'sources', 'state', 'states',
    ];

    /** Indicadores que identificam a história; setor, país e tipo de ataque são genéricos demais. */
    private const FIELDS = ['cves', 'affected_products', 'malware_families', 'threat_actors', 'victim_orgs'];

    /** Token presente em mais que esta fração da janela não diferencia nada e sai do índice invertido. */
    private const MAX_DF_RATIO = 0.08;

    /** Nota mínima só com os termos do índice invertido para o par ser medido inteiro. */
    private const MIN_PARTIAL = 0.06;

    /** Teto de matérias de um componente na ligação média (o custo cresce com o cubo). */
    private const MAX_COMPONENT = 60;

    /** @var array<int, array<string, float>> vetores TF-IDF normalizados da janela */
    private array $vectors = [];

    /** @var array<int, list<string>> */
    private array $cves = [];

    /**
     * Grupos de matérias parecidas: cada um tem ao menos uma matéria nova
     * (`latest_at` desde $since) e é comparado com a janela inteira.
     *
     * @return list<array{ids: list<int>, score: float}>
     */
    public function groups(?Carbon $since = null): array
    {
        $since ??= now()->subHours((int) config('secmon.merge.focus_hours'));

        $pool = NewsCase::query()
            ->where('is_published', true)
            ->where('latest_at', '>=', now()->subDays((int) config('secmon.merge.window_days')))
            ->orderBy('id')
            ->get(['id', 'title', 'summary', 'indicators', 'cves', 'latest_at']);

        $focus = $pool->filter(fn (NewsCase $case) => $case->latest_at?->gte($since))->modelKeys();

        return $this->cluster($pool, $focus);
    }

    /**
     * @param  Collection<int, NewsCase>  $pool
     * @param  list<int>  $focus  ids das matérias novas
     * @return list<array{ids: list<int>, score: float}>
     */
    public function cluster(Collection $pool, array $focus): array
    {
        $raw = [];
        $cves = [];

        foreach ($pool as $case) {
            $raw[$case->id] = $this->terms($case);
            $cves[$case->id] = array_map('strtoupper', $case->cves ?? []);
        }

        $this->vectors = $this->weigh($raw);
        $this->cves = $cves;

        $postings = $this->postings($this->vectors, count($this->vectors));
        $min = (float) config('secmon.merge.min_similarity');
        $isFocus = array_fill_keys($focus, true);
        $edges = [];

        foreach ($focus as $id) {
            if (! isset($this->vectors[$id])) {
                continue;
            }

            $partial = [];

            foreach ($this->vectors[$id] as $term => $weight) {
                foreach ($postings[$term] ?? [] as $other => $otherWeight) {
                    $partial[$other] = ($partial[$other] ?? 0.0) + $weight * $otherWeight;
                }
            }

            foreach ($partial as $other => $score) {
                // Par de duas matérias novas: conta uma vez só.
                if ($other === $id || (isset($isFocus[$other]) && $other < $id) || $score < self::MIN_PARTIAL) {
                    continue;
                }

                if ($this->similarity($id, $other) >= $min) {
                    $edges[] = [$id, $other];
                }
            }
        }

        $groups = [];

        foreach ($this->components($edges) as $component) {
            foreach ($this->averageLinkage($component, $min) as $ids) {
                if (count($ids) > 1 && array_intersect($ids, $focus) !== []) {
                    $groups[] = $this->trim($ids);
                }
            }
        }

        usort($groups, fn ($x, $y) => $y['score'] <=> $x['score']);

        return $groups;
    }

    /**
     * Nota do par: o cosseno inteiro (o índice invertido, sem os termos
     * comuns, só achou os candidatos) e um bônus para CVE em comum.
     */
    public function similarity(int $a, int $b): float
    {
        $score = $this->cosine($this->vectors[$a] ?? [], $this->vectors[$b] ?? []);

        if (array_intersect($this->cves[$a] ?? [], $this->cves[$b] ?? []) !== []) {
            $score += (float) config('secmon.merge.cve_bonus');
        }

        return $score;
    }

    /**
     * Termos crus da matéria (sem o peso do IDF).
     *
     * @return array<string, float>
     */
    public function terms(NewsCase $case): array
    {
        $tf = [];

        $add = function (string $text, float $weight) use (&$tf) {
            foreach ($this->tokens($text) as $token) {
                $tf[$token] = ($tf[$token] ?? 0.0) + $weight;
            }
        };

        $add((string) $case->title, 2.0);
        $add(Markdown::toText((string) $case->summary, 2500), 1.0);

        foreach (self::FIELDS as $field) {
            foreach (array_slice($case->indicatorValues($field), 0, 25) as $value) {
                $key = $field.':'.mb_strtolower(trim((string) $value));
                $tf[$key] = ($tf[$key] ?? 0.0) + 2.0;
            }
        }

        foreach ($case->cves ?? [] as $cve) {
            $key = 'cves:'.mb_strtolower(trim((string) $cve));
            $tf[$key] = max($tf[$key] ?? 0.0, 2.0);
        }

        return $tf;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $text = mb_strtolower(Str::ascii($text));
        $tokens = [];

        foreach (preg_split('/[^a-z0-9.\-]+/', $text) ?: [] as $token) {
            $token = trim($token, '.-');

            if (strlen($token) < 3 || in_array($token, self::STOPWORDS, true) || preg_match('/^\d{1,4}$/', $token)) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * TF sublinear × IDF, normalizado: o produto escalar já é o cosseno.
     *
     * @param  array<int, array<string, float>>  $raw
     * @return array<int, array<string, float>>
     */
    private function weigh(array $raw): array
    {
        $df = [];

        foreach ($raw as $terms) {
            foreach ($terms as $term => $_) {
                $df[$term] = ($df[$term] ?? 0) + 1;
            }
        }

        $n = count($raw);
        $weighted = [];

        foreach ($raw as $id => $terms) {
            $vector = [];
            $norm = 0.0;

            foreach ($terms as $term => $tf) {
                $weight = (1 + log($tf)) * (log(($n + 1) / ($df[$term] + 1)) + 1);
                $vector[$term] = $weight;
                $norm += $weight * $weight;
            }

            $norm = sqrt($norm) ?: 1.0;

            $weighted[$id] = array_map(fn ($w) => $w / $norm, $vector);
        }

        return $weighted;
    }

    /** @param  array<string, float>  $a  @param  array<string, float>  $b */
    private function cosine(array $a, array $b): float
    {
        if (count($a) > count($b)) {
            [$a, $b] = [$b, $a];
        }

        $dot = 0.0;

        foreach ($a as $term => $weight) {
            if (isset($b[$term])) {
                $dot += $weight * $b[$term];
            }
        }

        return $dot;
    }

    /**
     * Índice invertido termo → [id => peso], sem os termos que aparecem em
     * quase todas as matérias (pouco peso e muito custo).
     *
     * @return array<string, array<int, float>>
     */
    private function postings(array $vectors, int $n): array
    {
        $postings = [];

        foreach ($vectors as $id => $vector) {
            foreach ($vector as $term => $weight) {
                $postings[$term][$id] = $weight;
            }
        }

        $maxDf = max(5, (int) ceil($n * self::MAX_DF_RATIO));

        return array_filter($postings, fn ($ids) => count($ids) <= $maxDf);
    }

    /**
     * Componentes conexos dos pares.
     *
     * @param  list<array{0: int, 1: int}>  $edges
     * @return list<list<int>>
     */
    private function components(array $edges): array
    {
        $parent = [];

        $find = function (int $x) use (&$parent, &$find): int {
            $parent[$x] ??= $x;

            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };

        foreach ($edges as [$a, $b]) {
            $parent[$find($a)] = $find($b);
        }

        $members = [];

        foreach (array_keys($parent) as $id) {
            $members[$find($id)][] = $id;
        }

        return array_values($members);
    }

    /**
     * Ligação média dentro do componente: junta os dois grupos mais parecidos
     * enquanto a média de todos os pares entre eles passar do mínimo. Sem
     * isso, uma matéria que só cita a outra de passagem (um CVE em comum)
     * emendaria duas histórias diferentes num grupo só.
     *
     * @param  list<int>  $ids
     * @return list<list<int>>
     */
    private function averageLinkage(array $ids, float $min): array
    {
        if (count($ids) > self::MAX_COMPONENT) {
            $ids = array_slice($this->central($ids), 0, self::MAX_COMPONENT);
        }

        $sim = [];

        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                $sim[$a][$b] = $sim[$b][$a] = $this->similarity($a, $b);
            }
        }

        $clusters = array_map(fn ($id) => [$id], $ids);

        while (count($clusters) > 1) {
            $best = null;
            $bestScore = $min;

            foreach ($clusters as $i => $x) {
                foreach ($clusters as $j => $y) {
                    if ($j <= $i) {
                        continue;
                    }

                    $total = 0.0;

                    foreach ($x as $a) {
                        foreach ($y as $b) {
                            $total += $sim[$a][$b];
                        }
                    }

                    $average = $total / (count($x) * count($y));

                    if ($average >= $bestScore) {
                        [$best, $bestScore] = [[$i, $j], $average];
                    }
                }
            }

            if (! $best) {
                break;
            }

            $clusters[$best[0]] = [...$clusters[$best[0]], ...$clusters[$best[1]]];
            unset($clusters[$best[1]]);
        }

        return array_values($clusters);
    }

    /**
     * Grupo maior que o teto fica com as matérias mais centrais; nota do grupo
     * é a média dos pares.
     *
     * @param  list<int>  $ids
     * @return array{ids: list<int>, score: float}
     */
    private function trim(array $ids): array
    {
        $ids = array_slice($this->central($ids), 0, (int) config('secmon.merge.max_group'));
        $pairs = [];

        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                $pairs[] = $this->similarity($a, $b);
            }
        }

        sort($ids);

        return ['ids' => $ids, 'score' => round(array_sum($pairs) / max(1, count($pairs)), 3)];
    }

    /**
     * Do mais ao menos parecido com o resto do grupo.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function central(array $ids): array
    {
        $strength = [];

        foreach ($ids as $a) {
            $strength[$a] = 0.0;

            foreach ($ids as $b) {
                if ($a !== $b) {
                    $strength[$a] += $this->similarity($a, $b);
                }
            }
        }

        arsort($strength);

        return array_keys($strength);
    }
}
