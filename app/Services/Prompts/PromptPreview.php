<?php

namespace App\Services\Prompts;

use App\Models\AlertMatch;
use App\Models\Article;
use App\Models\ArticleAttempt;
use App\Models\CustomAnswerTerm;
use App\Models\Exploit;
use App\Models\GithubFinding;
use App\Models\GithubMonitor;
use App\Models\LookalikeAlert;
use App\Models\NewsCase;
use App\Models\PentestUnit;
use App\Services\Alerts\AlertReviewer;
use App\Services\Alerts\CustomAnswerExpander;
use App\Services\Enrichment\ArticleMediaReader;
use App\Services\Enrichment\EnrichmentPrompt;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Enrichment\ErrorExplainer;
use App\Services\Enrichment\ErrorReasons;
use App\Services\Enrichment\HermesEnricher;
use App\Services\Exploits\ExploitExplainer;
use App\Services\Github\ExtraInterpreter;
use App\Services\Github\GithubJudge;
use App\Services\Github\GithubTriage;
use App\Services\Lookalike\LookalikeReviewer;
use App\Services\Merge\CaseMerger;
use App\Services\Merge\MergeEditor;
use App\Services\Pentest\Hypotheses\HypothesisPrioritizer;
use App\Services\Review\ReportReviewer;
use App\Services\Review\ReviewVerdict;
use App\Services\Vulnerability\VulnerabilityExplainer;
use App\Support\TagCode;
use Illuminate\Support\Str;
use Throwable;

/**
 * As mensagens que o hermes recebe em cada etapa, montadas agora pelo mesmo
 * código das chamadas reais, com o registro mais recente do banco. Não chama
 * a IA: só mostra o texto.
 */
class PromptPreview
{
    public function __construct(
        private AlertReviewer $alerts,
        private LookalikeReviewer $lookalikes,
        private CaseMerger $merger,
        private VulnerabilityExplainer $vulnerabilities,
        private GithubTriage $githubTriage,
        private GithubJudge $githubJudge,
        private HypothesisPrioritizer $hypotheses,
    ) {}

    /** @return array{example: ?string, note: ?string, messages: list<array{role: string, content: string}>} */
    public function stage(string $slug): array
    {
        $nonce = bin2hex(random_bytes(4));

        try {
            return match ($slug) {
                'analise' => $this->enrichment(),
                'revisor' => $this->review($nonce),
                'midia' => $this->media(),
                'fusao-agrupar' => $this->mergeConfirm($nonce),
                'fusao-escrever' => $this->mergeWrite($nonce),
                'fusao-enriquecer' => $this->mergeEnrich($nonce),
                'juiz-alertas' => $this->alertJudge($nonce),
                'juiz-radar' => $this->lookalikeJudge($nonce),
                'exploits' => $this->exploit($nonce),
                'erros-explicar' => $this->analysisError($nonce),
                'vulnerabilidade' => $this->vulnerability($nonce),
                'respostas-escritas' => $this->customAnswer(),
                'github-adicional' => $this->githubExtra($nonce),
                'github-classificador' => $this->githubFinding($nonce, 'triage'),
                'github-juiz' => $this->githubFinding($nonce, 'judge'),
                'testes-prioridade' => $this->hypothesisOrder($nonce),
            };
        } catch (Throwable $e) {
            return self::empty('Não deu para montar o exemplo: '.$e->getMessage());
        }
    }

    private function enrichment(): array
    {
        // De preferência um artigo que entrou numa matéria existente: mostra a regra do enriquecimento.
        $article = Article::query()->where('status', 'enriched')->whereNotNull('case_id')->where('is_first', false)->latest('id')->first()
            ?? Article::query()->where('status', 'enriched')->whereNotNull('case_id')->latest('id')->first();

        if (! $article) {
            return self::empty('Nenhum artigo analisado ainda.');
        }

        $case = $article->is_first ? null : $article->newsCase;

        return [
            'example' => "Artigo #{$article->id} ({$article->source_host})".($case ? ', que entrou na matéria “'.Str::limit((string) $case->title, 90).'”' : ', que abriu uma matéria'),
            'note' => $case ? 'O relatório da matéria é o de agora; na hora da análise, era o daquele momento.' : null,
            'messages' => [
                ['role' => 'system', 'content' => HermesEnricher::system()],
                ['role' => 'user', 'content' => EnrichmentPrompt::user($article, $case)],
            ],
        ];
    }

    /** O artigo mais recente que abriu uma matéria, como se o rascunho fosse a análise gravada dele. */
    private function review(string $nonce): array
    {
        $article = Article::query()->where('status', 'enriched')->where('is_first', true)->whereNotNull('case_id')->latest('id')->first();

        if (! $article) {
            return self::empty('Nenhuma matéria aberta ainda.');
        }

        $draft = new EnrichmentResult(
            title: (string) $article->title,
            summary: (string) $article->summary,
            criticity: $article->criticity,
            indicators: $article->indicators ?? [],
            driver: 'hermes',
            titlePt: $article->title_pt,
            summaryPt: $article->summary_pt,
        );
        $verdict = new ReviewVerdict(ReviewVerdict::ADJUST, ReviewVerdict::PART, [
            ['field' => 'summary_pt', 'excerpt' => '…', 'instruction' => 'Exemplo de instrução do revisor.'],
        ]);

        return [
            'example' => "Artigo #{$article->id} ({$article->source_host}), que abriu a matéria “".Str::limit((string) $article->newsCase?->title, 90).'”',
            'note' => 'Na atualização de uma matéria, o relatório publicado vai junto como fonte. Quando o revisor pede ajuste, a análise recebe a mensagem dela com o pedido abaixo no fim (aqui com um problema de exemplo).',
            'messages' => [
                ['role' => 'system', 'content' => ReportReviewer::system()],
                ['role' => 'user', 'content' => TagCode::header($nonce).ReportReviewer::prompt($article, null, $draft, 'new', [], $nonce)],
                ['role' => 'user', 'content' => '(pedido de ajuste à análise)'.ReportReviewer::redoRequest($draft, 'new', $verdict)],
            ],
        ];
    }

    private function media(): array
    {
        $article = Article::query()->where('extraction', 'manual')->latest('id')->first() ?? Article::query()->latest('id')->first();

        if (! $article) {
            return self::empty('Nenhum artigo coletado ainda.');
        }

        return [
            'example' => "Artigo #{$article->id} ({$article->source_host}), supondo 2 prints e 1 figura",
            'note' => 'As imagens vão anexadas depois do texto, cada uma com o rótulo PRINT n ou FIGURE n. O exemplo mostra só o começo do texto do artigo.',
            'messages' => [
                ['role' => 'system', 'content' => ArticleMediaReader::system()],
                ['role' => 'user', 'content' => ArticleMediaReader::prompt($article, Str::limit((string) $article->content, 1500, ' […]'), 2, 1)],
            ],
        ];
    }

    private function mergeConfirm(string $nonce): array
    {
        $cases = NewsCase::query()->where('is_published', true)->latest('latest_at')->limit(3)->get()->sortBy('id')->values();

        if ($cases->count() < 2) {
            return self::empty('São precisas ao menos duas matérias publicadas.');
        }

        return [
            'example' => 'As 3 matérias mais recentes, só para mostrar o formato',
            'note' => 'Duas vezes ao dia (00:00 e 12:00) vão os grupos de matérias parecidas que a comparação de texto encontrou.',
            'messages' => [
                ['role' => 'system', 'content' => MergeEditor::confirmSystem()],
                ['role' => 'user', 'content' => TagCode::header($nonce).MergeEditor::confirmPrompt($cases, $nonce)],
            ],
        ];
    }

    private function mergeWrite(string $nonce): array
    {
        $case = NewsCase::query()->where('is_published', true)->has('articles', '>=', 2)->latest('latest_at')->first();

        if (! $case) {
            return self::empty('Nenhuma matéria com duas ou mais fontes.');
        }

        return [
            'example' => 'As fontes da matéria “'.Str::limit((string) $case->title, 90).'”',
            'note' => null,
            'messages' => [
                ['role' => 'system', 'content' => MergeEditor::writeSystem()],
                ['role' => 'user', 'content' => TagCode::header($nonce).MergeEditor::writePrompt($this->merger->sources([$case->id]), $nonce)],
            ],
        ];
    }

    private function mergeEnrich(string $nonce): array
    {
        $case = NewsCase::query()->where('is_published', true)->whereNotNull('consolidated_at')->latest('latest_at')->first();
        $article = $case?->articles()->where('status', 'enriched')->latest('collected_at')->first();

        if (! $case || ! $article) {
            return self::empty('Nenhuma matéria consolidada ainda.');
        }

        return [
            'example' => 'A matéria consolidada “'.Str::limit((string) $case->title, 90).'”, com a fonte mais recente dela como material novo',
            'note' => null,
            'messages' => [
                ['role' => 'system', 'content' => MergeEditor::enrichSystem()],
                ['role' => 'user', 'content' => TagCode::header($nonce).MergeEditor::enrichPrompt($case, collect(), collect([$article]), $nonce)],
            ],
        ];
    }

    private function alertJudge(string $nonce): array
    {
        $match = AlertMatch::query()->whereHas('profile')->whereHas('case')->latest('id')->first();

        if (! $match) {
            return self::empty('Nenhum alerta ainda.');
        }

        return [
            'example' => "Alerta #{$match->id}, de “".Str::limit((string) $match->case->title, 80).'”',
            'note' => null,
            'messages' => [
                ['role' => 'system', 'content' => AlertReviewer::system($match->profile->language === 'en' ? 'English' : 'Portuguese (Brazil)')],
                ['role' => 'user', 'content' => TagCode::header($nonce).$this->alerts->prompt($match, $nonce)],
            ],
        ];
    }

    private function lookalikeJudge(string $nonce): array
    {
        $alert = LookalikeAlert::query()->whereHas('lookalike')->latest('id')->first();

        if (! $alert) {
            return self::empty('Nenhum aviso do radar ainda.');
        }

        return [
            'example' => "Aviso #{$alert->id} do radar",
            'note' => 'As capturas de tela (site do cliente e domínio suspeito, por aparelho) vão anexadas depois do texto.',
            'messages' => [
                ['role' => 'system', 'content' => LookalikeReviewer::system()],
                ['role' => 'user', 'content' => TagCode::header($nonce).$this->lookalikes->prompt($alert, $nonce)],
            ],
        ];
    }

    private function exploit(string $nonce): array
    {
        $exploit = Exploit::query()->whereHas('cases')->latest('published_on')->first();

        if (! $exploit) {
            return self::empty('Nenhum exploit ligado a matéria ainda.');
        }

        return [
            'example' => "EDB-{$exploit->id}: ".Str::limit((string) $exploit->description, 90),
            'note' => 'O arquivo do exploit é baixado do Exploit-DB na hora da chamada; aqui ele aparece só como marcador.',
            'messages' => [
                ['role' => 'system', 'content' => ExploitExplainer::system()],
                ['role' => 'user', 'content' => TagCode::header($nonce).ExploitExplainer::prompt($exploit, '(arquivo do exploit)', $nonce)],
            ],
        ];
    }

    private function analysisError(string $nonce): array
    {
        // O último erro que o mapa fixo não conhece; sem nenhum, um exemplo.
        $attempt = ArticleAttempt::query()->whereNotNull('error')->latest('id')->limit(200)->get()
            ->first(fn (ArticleAttempt $attempt) => app(ErrorReasons::class)->known($attempt->error) === null);
        $error = $attempt?->error ?? 'claude: Unexpected value for stop_reason: "pause_turn"';

        return [
            'example' => $attempt ? "artigo #{$attempt->article_id}: ".Str::limit($error, 90) : 'erro de exemplo (nenhum erro desconhecido no histórico)',
            'note' => 'Vai só o erro, o driver, a etapa e o tamanho do artigo; o texto do artigo nunca vai.',
            'messages' => [
                ['role' => 'system', 'content' => ErrorExplainer::system()],
                ['role' => 'user', 'content' => TagCode::header($nonce).ErrorExplainer::prompt($error, $attempt?->driver ?? 'claude', 'article analysis (coleta)', (int) ($attempt?->article?->content_chars ?? 5400), $nonce)],
            ],
        ];
    }

    private function vulnerability(string $nonce): array
    {
        // A matéria de vulnerabilidade mais recente; de preferência uma já explicada, que mostra a explicação anterior.
        $cases = NewsCase::query()->where('is_published', true)->latest('latest_at')->limit(200)->get()
            ->filter(fn (NewsCase $case) => VulnerabilityExplainer::candidate($case));
        $case = $cases->first(fn (NewsCase $case) => $case->vulnerability_applies) ?? $cases->first();

        if (! $case) {
            return self::empty('Nenhuma matéria de vulnerabilidade ainda.');
        }

        return [
            'example' => 'A matéria “'.Str::limit((string) $case->title, 90).'”',
            'note' => null,
            'messages' => [
                ['role' => 'system', 'content' => VulnerabilityExplainer::system()],
                ['role' => 'user', 'content' => TagCode::header($nonce).VulnerabilityExplainer::prompt($case, $this->vulnerabilities->articles($case), $nonce)],
            ],
        ];
    }

    private function customAnswer(): array
    {
        $answer = CustomAnswerTerm::query()->latest('id')->first();
        [$kind, $label] = $answer ? [$answer->kind, $answer->label] : ['tech', 'SAP S/4HANA'];

        return [
            'example' => $answer
                ? 'A resposta mais recente: “'.$label.'” ('.(CustomAnswerTerm::KINDS[$kind] ?? $kind).')'
                : 'Nenhuma resposta escrita ainda; o exemplo usa “SAP S/4HANA” (tecnologia)',
            'note' => 'Cada tipo de resposta (setor, região, tipo de ataque, tecnologia) usa o pedido dele.',
            'messages' => [
                ['role' => 'system', 'content' => CustomAnswerExpander::system()],
                ['role' => 'user', 'content' => CustomAnswerExpander::task($kind, $label)],
            ],
        ];
    }

    private function githubExtra(string $nonce): array
    {
        $monitor = GithubMonitor::query()->whereNotNull('extra_text')->latest('updated_at')->first();
        $text = $monitor?->extra_text ?? 'O app mobile usa chaves que começam com FMK_. O repositório interno chama fm-core.';

        return [
            'example' => $monitor ? 'O texto adicional mais recente (monitoramento #'.$monitor->id.')' : 'Nenhum texto adicional ainda; o exemplo usa um texto inventado',
            'note' => 'Só valores escritos no texto do cliente são aproveitados; o resto da resposta é descartado.',
            'messages' => [
                ['role' => 'system', 'content' => ExtraInterpreter::system()],
                ['role' => 'user', 'content' => TagCode::header($nonce).ExtraInterpreter::user($monitor ?? new GithubMonitor, $text, $nonce)],
            ],
        ];
    }

    private function githubFinding(string $nonce, string $stage): array
    {
        $finding = GithubFinding::query()
            ->when($stage === 'judge', fn ($q) => $q->whereNotNull('ai_reviewed_at'))
            ->with('monitor')
            ->latest('id')
            ->first() ?? GithubFinding::query()->with('monitor')->latest('id')->first();

        if (! $finding) {
            return self::empty('Nenhum achado do GitHub ainda.');
        }

        return [
            'example' => 'Achado #'.$finding->id.': '.Str::limit($finding->location(), 90),
            'note' => 'Os valores das chaves já chegam mascarados: a IA nunca vê o segredo.',
            'messages' => [
                ['role' => 'system', 'content' => $stage === 'judge' ? GithubJudge::system() : GithubTriage::system()],
                ['role' => 'user', 'content' => TagCode::header($nonce).($stage === 'judge' ? $this->githubJudge->prompt($finding, $nonce) : $this->githubTriage->prompt($finding, $nonce))],
            ],
        ];
    }

    /** A execução mais recente com hipótese planejada: o prompt já sai pseudonimizado (alvo-N, h-N). */
    private function hypothesisOrder(string $nonce): array
    {
        $unit = PentestUnit::query()->where('gate', 'allow')->where('coverage', 'planned')->with('run')->latest('id')->first();

        if (! $unit) {
            return self::empty('Nenhum teste de segurança chegou às hipóteses ainda.');
        }

        return [
            'example' => 'A execução '.$unit->run->shortId().', com '.PentestUnit::query()->where('run_id', $unit->run_id)->where('gate', 'allow')->where('coverage', 'planned')->count().' hipóteses planejadas',
            'note' => 'Os alvos e as hipóteses vão com pseudônimos, e a versão das tecnologias, cercada: a IA nunca vê o domínio, o IP ou o caminho.',
            'messages' => [
                ['role' => 'system', 'content' => HypothesisPrioritizer::system($nonce)],
                ['role' => 'user', 'content' => $this->hypotheses->prompt($unit->run, PentestUnit::query()->where('run_id', $unit->run_id)->get(), $nonce)['text']],
            ],
        ];
    }

    private static function empty(string $note): array
    {
        return ['example' => null, 'note' => $note, 'messages' => []];
    }
}
