<?php

namespace App\Services\Admin;

use App\Exceptions\ActionRefused;
use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Review\ReviewLoop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * O que está nas filas (tabelas `jobs` e `failed_jobs`) em linguagem de gente:
 * que trabalho é, sobre qual artigo ou matéria, desde quando e, no falho, o
 * motivo. Lê o payload sem unserialize (só os ids do job), então nada do job
 * é instanciado só para aparecer na tela.
 *
 * Usado em Admin → Fila (os cartões "Jobs na fila" e "Jobs falhos" do painel)
 * e no quadro "IA agora" de Admin → Coletas.
 */
class QueueMonitor
{
    /** Jobs que chamam a IA (o hermes), com o nome que aparece na tela. */
    public const AI_JOBS = [
        'EnrichArticle' => 'Análise do artigo',
        'ReadArticleMedia' => 'Leitura dos prints e imagens do artigo',
        'RebuildCase' => 'Relatório da matéria',
        'ExplainVulnerability' => 'Explicação da vulnerabilidade',
        'ExplainExploit' => 'Explicação do exploit',
        'MergeSimilarCases' => 'Fusão de matérias',
        'ReviewAlertMatches' => 'Parecer dos alertas',
        'ReviewGithubFinding' => 'Parecer do achado do GitHub',
        'TriageGithubFinding' => 'Triagem do achado do GitHub',
        'InterpretGithubExtra' => 'Interpretação do monitoramento do GitHub',
        'ReviewLookalikeAlert' => 'Parecer do Radar',
        'ExpandCustomAnswer' => 'Resposta personalizada da entrevista',
        'PrioritizePentestHypotheses' => 'Ordem das hipóteses do teste de segurança',
    ];

    private const OTHER_JOBS = [
        'RunCrawler' => 'Coleta das fontes',
        'MatchCaseToProfiles' => 'Matéria × perfis de alerta',
        'DiscoverDomainStack' => 'Descoberta das tecnologias do domínio',
        'ScanGithub' => 'Varredura do GitHub',
        'ScanLookalikes' => 'Varredura do Radar',
        'CheckLookalikeNames' => 'Radar: lote de DNS',
        'CheckLookalikeCertificates' => 'Radar: certificados',
        'FinishLookalikeScan' => 'Radar: fechamento da varredura',
        'ScanSecuritySite' => 'Monitoria de segurança',
        'AdvancePentestRun' => 'Teste de segurança',
        'SendChannelMessage' => 'Envio de notificação',
        'SendSlackMessage' => 'Envio ao Slack',
        'SendDiscordMessage' => 'Envio ao Discord',
        'SendTelegramMessage' => 'Envio ao Telegram',
        'SendWebhookMessage' => 'Envio ao webhook',
        'SendEmail' => 'Envio de e-mail',
        'NotifyPentestReady' => 'Aviso do teste de segurança',
    ];

    /**
     * Agendados que o botão "Adiantar" não mexe: a espera é a regra do próprio job, e adiantar atrapalha.
     */
    private const HELD_JOBS = [
        // O passo tem hora marcada (`not_before`): o job que chega antes sai sem rodar, e o passo só volta pelo
        // vigia dos parados, minutos depois. Adiantar atrasaria o teste.
        'AdvancePentestRun' => 'o passo do teste de segurança tem hora marcada (executor, janela ou ritmo do site); o job que chega antes sai sem rodar e o teste atrasaria',
        // Confere a cada 15 s se os lotes acabaram, com um número máximo de conferências: adiantar só gasta uma.
        'FinishLookalikeScan' => 'o Radar confere a cada 15 s se os lotes de DNS acabaram; adiantar só gastaria uma das conferências',
    ];

    /** Jobs enfileirados com atraso de propósito (sem tentativa ainda) e o porquê. */
    private const DELAYED_JOBS = [
        'ExplainVulnerability' => 'espera a matéria assentar para os artigos da mesma coleta irem numa chamada só à IA',
        'SubmitIndexNow' => 'espera a rodada da coleta terminar para avisar o IndexNow de todas as matérias juntas',
    ];

    /** @return array{fila: int, falhos: int} */
    public function counts(): array
    {
        return ['fila' => DB::table('jobs')->count(), 'falhos' => DB::table('failed_jobs')->count()];
    }

    /** Os outros ids que um job carrega, para a linha "sobre o quê". */
    private const ID_NAMES = [
        'exploitId' => 'exploit',
        'findingId' => 'achado do GitHub',
        'monitorId' => 'monitoramento do GitHub',
        'alertId' => 'alerta do Radar',
        'answerId' => 'resposta da entrevista',
        'matchIds' => 'alertas',
        'watchId' => 'marca do Radar',
        'siteId' => 'site',
        'runId' => 'execução',
        'callId' => 'chamada da IA do teste',
    ];

    /** Jobs que estão na fila agora: os que um worker pegou (rodando) primeiro, depois a ordem em que vão rodar. */
    public function queued(): Collection
    {
        $now = now();
        $rows = DB::table('jobs')
            ->orderByRaw('reserved_at is null')->orderBy('reserved_at')->orderBy('available_at')->orderBy('id')
            ->limit(500)->get();

        return $this->withSubjects($rows->map(function ($row) use ($now) {
            $job = $this->describe($row->payload);
            $available = Carbon::createFromTimestamp($row->available_at);

            $job['id'] = $row->id;
            $job['queue'] = $row->queue;
            $job['attempts'] = (int) $row->attempts;
            $job['created_at'] = Carbon::createFromTimestamp($row->created_at);
            $job['running_since'] = $row->reserved_at ? Carbon::createFromTimestamp($row->reserved_at) : null;
            $job['state'] = match (true) {
                $row->reserved_at !== null => 'rodando',
                $available->greaterThan($now) => 'agendado',
                default => 'aguardando',
            };
            $job['hold'] = $job['state'] === 'agendado' ? $this->hold($job['class'], (int) $row->attempts) : null;
            $job['reason'] = match ($job['state']) {
                'rodando' => 'Um worker está executando há '.$job['running_since']->diffForHumans($now, true)
                    .($job['timeout'] ? ' (limite de '.$this->duration($job['timeout']).')' : '').'.',
                'agendado' => 'Agendado para '.$available->copy()->timezone('America/Sao_Paulo')->format('d/m H:i')
                    .' (daqui a '.$available->diffForHumans($now, true).'): '.$job['hold']['text'].'.',
                default => $row->attempts > 0
                    ? 'Esperando um worker livre; já teve '.$row->attempts.' tentativa(s).'
                    : 'Esperando um worker livre da fila `'.$row->queue.'` desde '.$job['created_at']->diffForHumans($now, true).'.',
            };

            return $job;
        }));
    }

    /** Jobs que falharam, do mais recente: o motivo é a 1ª linha da exceção; o resto vai no detalhe. */
    public function failed(): Collection
    {
        $rows = DB::table('failed_jobs')->orderByDesc('failed_at')->orderByDesc('id')->limit(200)->get();

        return $this->withSubjects($rows->map(function ($row) {
            $job = $this->describe($row->payload);
            $exception = (string) $row->exception;

            $job['id'] = $row->id;
            $job['uuid'] = $row->uuid;
            $job['queue'] = $row->queue;
            $job['failed_at'] = Carbon::parse($row->failed_at, 'UTC');
            $job['reason'] = $this->reason($exception);
            $job['trace'] = mb_substr($exception, 0, 6000);

            return $job;
        }));
    }

    /** Devolve o job falho à fila (o queue:retry do Laravel: tentativas zeradas, mesmo payload). */
    public function retry(string $uuid): string
    {
        $job = $this->failedJob($uuid);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Log::info('Admin → Fila: job falho devolvido à fila', ['uuid' => $uuid, 'job' => $job['class']]);

        return $job['label'].' voltou para a fila `'.$job['queue'].'`.';
    }

    public function forget(string $uuid): string
    {
        $job = $this->failedJob($uuid);
        app('queue.failer')->forget($uuid);
        Log::info('Admin → Fila: job falho apagado', ['uuid' => $uuid, 'job' => $job['class']]);

        return $job['label'].' apagado da lista de falhos.';
    }

    public function retryAll(): string
    {
        $total = $this->counts()['falhos'];

        if ($total === 0) {
            throw new ActionRefused('Não há job falho para tentar de novo.');
        }

        Artisan::call('queue:retry', ['id' => ['all']]);
        Log::info('Admin → Fila: todos os jobs falhos devolvidos à fila', ['total' => $total]);

        return $total.' job(s) voltaram para a fila.';
    }

    public function forgetAll(): string
    {
        $total = $this->counts()['falhos'];
        app('queue.failer')->flush();
        Log::info('Admin → Fila: lista de jobs falhos apagada', ['total' => $total]);

        return $total.' job(s) falho(s) apagado(s).';
    }

    /**
     * Adianta um job agendado: o `available_at` vira agora e o worker da fila o pega na próxima volta.
     * A condição vai no próprio UPDATE: o job que um worker acabou de pegar (ou que já estava
     * disponível) não muda.
     */
    public function advance(int $id): string
    {
        $row = DB::table('jobs')->where('id', $id)->first(['id', 'queue', 'payload', 'attempts']);

        if (! $row) {
            throw new ActionRefused('Esse job já começou ou já não estava agendado.');
        }

        $job = $this->describe($row->payload);
        $hold = $this->hold($job['class'], (int) $row->attempts);

        if (! $hold['advance']) {
            throw new ActionRefused($job['label'].' não se adianta daqui: '.$hold['text'].'.');
        }

        if ($this->advanceRows([$id]) === 0) {
            throw new ActionRefused('Esse job já começou ou já não estava agendado.');
        }

        Log::info('Admin → Fila: job agendado adiantado', ['id' => $id, 'job' => $job['class'], 'fila' => $row->queue]);

        return 'Adiantado: '.$job['label'].' roda na próxima volta do worker da fila `'.$row->queue.'`.';
    }

    /** Adianta todos os agendados que o botão cobre (os de HELD_JOBS ficam como estão). */
    public function advanceAll(): string
    {
        $ids = $this->scheduled()->where('advance', true)->pluck('id')->all();
        $total = $ids === [] ? 0 : $this->advanceRows($ids);

        if ($total === 0) {
            throw new ActionRefused('Não há job agendado para adiantar.');
        }

        Log::info('Admin → Fila: jobs agendados adiantados', ['total' => $total]);

        return $total.' job(s) adiantado(s): rodam na próxima volta do worker de cada fila.';
    }

    /**
     * Quantos agendados o "Adiantar todos" pega e quantos deles esperam o limite da IA (para a confirmação).
     *
     * @return array{total: int, ai_limit: int}
     */
    public function advanceable(): array
    {
        $jobs = $this->scheduled()->where('advance', true);

        return ['total' => $jobs->count(), 'ai_limit' => $jobs->where('ai_limit', true)->count()];
    }

    /** Os agendados (sem worker e com hora no futuro), com o que o botão pode fazer com cada um. */
    private function scheduled(): Collection
    {
        return DB::table('jobs')->whereNull('reserved_at')->where('available_at', '>', now()->getTimestamp())
            ->get(['id', 'payload', 'attempts'])
            ->map(function ($row) {
                $hold = $this->hold($this->describe($row->payload)['class'], (int) $row->attempts);

                return ['id' => $row->id, 'advance' => $hold['advance'], 'ai_limit' => $hold['ai_limit']];
            });
    }

    /** @param  list<int>  $ids */
    private function advanceRows(array $ids): int
    {
        $now = now()->getTimestamp();

        return DB::table('jobs')->whereIn('id', $ids)->whereNull('reserved_at')->where('available_at', '>', $now)
            ->update(['available_at' => $now]);
    }

    /**
     * Por que o job está agendado, pelo que a linha da tabela `jobs` mostra (a classe e as
     * tentativas), e se o botão "Adiantar" cabe nele.
     *
     * @return array{text: string, advance: bool, ai_limit: bool}
     */
    private function hold(string $class, int $attempts): array
    {
        if (isset(self::HELD_JOBS[$class])) {
            return ['text' => self::HELD_JOBS[$class], 'advance' => false, 'ai_limit' => false];
        }

        $text = match (true) {
            // Só a fusão volta à fila pelo limite da IA (HermesLimitReached), com a hora que o hermes deu.
            $class === 'MergeSimilarCases' && $attempts > 0 => 'esperando o limite da IA (hermes); adiantado, volta a esperar se a conta ainda estiver no limite',
            $class === 'EnrichArticle' && $attempts > 0 => 'esperando outro artigo da mesma matéria terminar a análise (volta em 1 min)',
            $attempts > 0 => 'nova tentativa depois de uma falha (tentativa '.$attempts.'); adiantado, gasta a próxima tentativa agora e, se a causa continuar, vai aos falhos mais cedo',
            default => self::DELAYED_JOBS[$class] ?? 'foi enfileirado com atraso de propósito',
        };

        return ['text' => $text, 'advance' => true, 'ai_limit' => $class === 'MergeSimilarCases' && $attempts > 0];
    }

    /** @return array{class: string, label: string, queue: string} */
    private function failedJob(string $uuid): array
    {
        $row = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        if (! $row) {
            throw new ActionRefused('Esse job não está mais na lista de falhos (outra pessoa tentou de novo ou apagou).');
        }

        return $this->describe($row->payload) + ['queue' => $row->queue];
    }

    /**
     * O quadro "IA agora" de Coletas: o que a IA está fazendo, o que espera por
     * ela, e quanto do que foi coletado no período ela já analisou.
     *
     * @return array{running: Collection, waiting: Collection, failed: int, analyzed: int, collected: int, percent: ?int}
     */
    public function aiNow(Carbon $since): array
    {
        $ai = $this->queued()->where('ai', true);
        $collected = Article::query()->where('collected_at', '>=', $since)->count();
        $waiting = Article::query()->where('collected_at', '>=', $since)->whereIn('status', ['pending', 'failed'])->count();

        return [
            // A análise e o refazer com o revisor ligado: em que rodada ele está.
            'running' => $ai->where('state', 'rodando')->map(fn (array $job) => $job + [
                'review' => in_array($job['class'], ['EnrichArticle', 'RebuildCase'], true)
                    ? ReviewLoop::progress($job['ids']['articleId'] ?? null, $job['class'] === 'RebuildCase' ? ($job['ids']['caseId'] ?? null) : null)
                    : null,
            ])->values(),
            'waiting' => $ai->where('state', '!=', 'rodando')->groupBy('label')->map->count()->sortDesc(),
            'failed' => $this->counts()['falhos'],
            'analyzed' => $collected - $waiting,
            'collected' => $collected,
            'percent' => $collected > 0 ? (int) floor(($collected - $waiting) / $collected * 100) : null,
        ];
    }

    /** @return array{class: string, label: string, ai: bool, timeout: ?int, ids: array<string, int|list<int>>} */
    private function describe(string $payload): array
    {
        $data = json_decode($payload, true) ?: [];
        $class = class_basename((string) ($data['displayName'] ?? $data['data']['commandName'] ?? 'desconhecido'));
        $command = (string) ($data['data']['command'] ?? '');

        // Só os ids (articleId, caseId, caseIds…): o timeout, os tries e afins também vão serializados.
        $ids = [];
        preg_match_all('/s:\d+:"(\w+Ids?)";(?:i:(\d+);|a:\d+:\{((?:i:\d+;i:\d+;)*)\})/', $command, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            if (($m[2] ?? '') !== '') {
                $ids[$m[1]] = (int) $m[2];
            } elseif (($m[3] ?? '') !== '') {
                preg_match_all('/i:\d+;i:(\d+);/', $m[3], $list);
                $ids[$m[1]] = array_map('intval', $list[1]);
            }
        }

        return [
            'class' => $class,
            'label' => self::AI_JOBS[$class] ?? self::OTHER_JOBS[$class] ?? $class,
            'ai' => isset(self::AI_JOBS[$class]),
            'timeout' => isset($data['timeout']) ? (int) $data['timeout'] : null,
            'ids' => $ids,
        ];
    }

    /** O artigo ou a matéria de cada job, numa consulta por tabela. */
    private function withSubjects(Collection $jobs): Collection
    {
        $articleIds = $jobs->pluck('ids.articleId')->filter()->unique();
        $caseIds = $jobs->flatMap(fn ($job) => array_merge((array) ($job['ids']['caseId'] ?? []), (array) ($job['ids']['caseIds'] ?? [])))->filter()->unique();

        $articles = $articleIds->isEmpty() ? collect() : Article::query()->whereIn('id', $articleIds)->get(['id', 'title', 'original_title', 'source_host'])->keyBy('id');
        $cases = $caseIds->isEmpty() ? collect() : NewsCase::query()->whereIn('id', $caseIds)->get(['id', 'uuid', 'title'])->keyBy('id');

        return $jobs->map(function (array $job) use ($articles, $cases) {
            $subjects = [];

            if ($id = $job['ids']['articleId'] ?? null) {
                $article = $articles->get($id);
                $subjects[] = ['text' => $article ? ($article->title ?: $article->original_title ?: 'artigo #'.$id).' · '.$article->source_host : 'artigo #'.$id.' (já apagado)', 'url' => null];
            }

            foreach (array_merge((array) ($job['ids']['caseId'] ?? []), (array) ($job['ids']['caseIds'] ?? [])) as $id) {
                $case = $cases->get($id);
                $subjects[] = $case
                    ? ['text' => $case->title, 'url' => route('feed.show', $case->uuid)]
                    : ['text' => 'matéria #'.$id.' (já saiu do ar)', 'url' => null];
            }

            if ($subjects === []) {
                foreach ($job['ids'] as $name => $value) {
                    $subjects[] = ['text' => (self::ID_NAMES[$name] ?? $name).' #'.implode(', #', (array) $value), 'url' => null];
                }
            }

            return $job + ['subjects' => $subjects];
        });
    }

    /** A 1ª linha da exceção, sem o "in /var/www/html/…:69" do fim. */
    private function reason(string $exception): string
    {
        $first = trim(strtok($exception, "\n") ?: '');
        $first = (string) preg_replace('/ in \/\S+:\d+$/', '', $first);

        return mb_substr($first, 0, 500);
    }

    private function duration(int $seconds): string
    {
        return $seconds >= 60 ? intdiv($seconds, 60).' min' : $seconds.' s';
    }
}
