<?php

namespace App\Services\Crawler;

use App\Models\Article;
use App\Models\CaseMerge;
use App\Models\NewsCase;
use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * Decide se um artigo recém-coletado é uma matéria nova ou mais uma fonte
 * falando do mesmo caso — é isto que faz o feed não ficar repetitivo.
 *
 * A comparação usa três sinais:
 *   1. CVEs em comum (sinal forte: duas notícias sobre a mesma CVE quase sempre
 *      são o mesmo caso dentro da janela);
 *   2. similaridade de Jaccard entre os tokens significativos do título;
 *   3. pelo menos um token *distintivo* em comum — sem isso, feeds cujos
 *      títulos seguem um molde fixo ("Have I Been Pwned: <empresa> Data
 *      Breach") colapsariam incidentes diferentes num único caso, porque o
 *      molde sozinho já passa do limiar de similaridade.
 */
class CaseResolver
{
    private const STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'has', 'have',
        'in', 'is', 'it', 'its', 'of', 'on', 'or', 'that', 'the', 'to', 'was', 'were',
        'with', 'after', 'new', 'now', 'via', 'over', 'into', 'more', 'how', 'what',
        'says', 'said', 'report', 'reports', 'update', 'updates', 'news',
    ];

    /** Acima desta fração de casos candidatos, o token é molde, não conteúdo. */
    private const COMMON_TOKEN_RATIO = 0.10;

    /** Tokens normalizados usados como assinatura do caso. */
    public function fingerprint(string $title): string
    {
        $title = mb_strtolower(Str::ascii($title));
        $title = (string) preg_replace('/[^a-z0-9\s\-\.]/', ' ', $title);

        $tokens = array_values(array_filter(
            preg_split('/\s+/', $title) ?: [],
            fn ($t) => mb_strlen($t) >= 3 && ! in_array($t, self::STOPWORDS, true)
        ));

        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return implode(' ', $tokens);
    }

    /**
     * Procura o caso ao qual o artigo pertence. Devolve null quando é matéria nova.
     */
    public function resolve(Article $article, array $cves = []): ?NewsCase
    {
        $title = (string) ($article->original_title ?: $article->title);

        if (trim($title) === '') {
            return null;
        }

        $tokens = array_values(array_filter(explode(' ', $this->fingerprint($title))));

        if ($tokens === []) {
            return null;
        }

        $since = now()->subDays((int) config('secmon.crawler.dedup_window_days'));
        $threshold = Settings::float('crawler.dedup_threshold');

        // Só os campos usados na pontuação: a lista de candidatos é grande e não
        // vale carregar resumo e indicadores de todos. A matéria escondida do
        // feed público fica de fora: a fonte nova abre matéria própria, em vez
        // de sumir junto com ela.
        $candidates = NewsCase::query()
            ->where('is_published', true)
            ->where('latest_at', '>=', $since)
            ->orderByDesc('latest_at')
            ->limit(400)
            ->get(['id', 'title', 'fingerprint', 'cves']);

        if ($candidates->isEmpty()) {
            return null;
        }

        $common = $this->commonTokens($candidates);

        // Matérias apagadas pela fusão de matérias seguem valendo pelo título e
        // pelos CVEs, apontando para a matéria nova: sem isso, a próxima fonte
        // de uma delas abriria outra matéria separada da mesma história.
        $merged = CaseMerge::query()
            ->whereIn('case_id', $candidates->modelKeys())
            ->get(['case_id', 'title', 'fingerprint', 'cves'])
            ->map(fn (CaseMerge $m) => (new NewsCase)->forceFill([
                'id' => $m->case_id,
                'title' => $m->title,
                'fingerprint' => $m->fingerprint,
                'cves' => $m->cves,
            ]));

        $bestId = null;
        $bestScore = 0.0;

        foreach ($candidates->concat($merged) as $candidate) {
            $score = $this->score($tokens, $cves, $candidate, $common);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $candidate->id;
            }
        }

        // Devolve o modelo completo: quem grava precisa dos contadores, e um
        // modelo parcial faria `update_count + 1` virar 1 em vez de incrementar.
        return $bestScore >= $threshold ? NewsCase::find($bestId) : null;
    }

    /**
     * Tokens que aparecem em muitos casos recentes — na prática, vocabulário
     * genérico ("data", "breach") e branding de feed ("pwned"). São ignorados
     * na hora de exigir um ponto de contato distintivo.
     *
     * @return array<string, true>
     */
    private function commonTokens($candidates): array
    {
        $frequency = [];

        foreach ($candidates as $candidate) {
            foreach (array_unique(array_filter(explode(' ', (string) $candidate->fingerprint))) as $token) {
                $frequency[$token] = ($frequency[$token] ?? 0) + 1;
            }
        }

        $cutoff = max(3, (int) ceil($candidates->count() * self::COMMON_TOKEN_RATIO));

        return array_fill_keys(
            array_keys(array_filter($frequency, fn ($count) => $count >= $cutoff)),
            true
        );
    }

    private function score(array $tokens, array $cves, NewsCase $candidate, array $common): float
    {
        $caseTokens = array_filter(explode(' ', (string) $candidate->fingerprint));

        if ($caseTokens === []) {
            $caseTokens = array_filter(explode(' ', $this->fingerprint((string) $candidate->title)));
        }

        $caseCves = array_map('strtoupper', $candidate->cves ?? []);
        $sharedCves = array_intersect(array_map('strtoupper', $cves), $caseCves);

        $shared = array_intersect($tokens, $caseTokens);
        $jaccard = $this->jaccard($tokens, $caseTokens);

        if ($sharedCves !== []) {
            // CVE em comum é sinal forte, mas ainda exige alguma afinidade
            // textual para não fundir "patch disponível" com "explorado no
            // mundo real" quando a CVE é só citada de passagem.
            return max($jaccard, 0.55 + min(0.3, $jaccard));
        }

        // Sem CVE compartilhada, é preciso ao menos um termo específico em
        // comum: título montado sobre molde fixo não basta.
        $distinctive = array_filter($shared, fn ($token) => ! isset($common[$token]));

        return $distinctive === [] ? 0.0 : $jaccard;
    }

    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
