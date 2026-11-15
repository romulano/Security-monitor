<?php

namespace App\Services\Admin;

use App\Exceptions\ActionRefused;
use App\Jobs\RunCrawler;
use App\Models\Article;
use App\Models\CrawlRun;
use App\Models\Source;
use App\Services\Crawler\CrawlerService;
use App\Support\Settings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/** Admin → Fontes e Execuções: o cadastro das fontes e as coletas pedidas à mão. */
class SourceManager
{
    public function __construct(private CrawlerService $crawler) {}

    public function list(?string $search): LengthAwarePaginator
    {
        return Source::query()
            ->when($search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%")->orWhere('host', 'ilike', "%{$s}%"))
            ->orderByDesc('enabled')
            ->orderBy('host')
            ->paginate(50)
            ->withQueryString();
    }

    /** @param  array{name: string, feed_url: string, host: ?string, interval_minutes: int, max_items: ?int, proxy_url?: ?string}  $data */
    public function create(array $data): Source
    {
        $host = $data['host'] ?: preg_replace('/^www\./', '', (string) parse_url($data['feed_url'], PHP_URL_HOST));

        return Source::create([
            'name' => $data['name'],
            'host' => $host,
            'feed_url' => $data['feed_url'],
            'site_url' => 'https://'.$host,
            'enabled' => true,
            'interval_minutes' => $data['interval_minutes'],
            'max_items' => $data['max_items'] ?: null,
            'proxy_url' => ($data['proxy_url'] ?? null) ?: null,
        ]);
    }

    /**
     * O campo do proxy chega sempre vazio na tela (a senha nunca volta para o
     * HTML): vazio mantém o proxy atual, preenchido troca e `remove_proxy` apaga.
     *
     * @param  array{name: string, interval_minutes: int, max_items: ?int, proxy_url?: ?string, remove_proxy?: bool}  $data
     */
    public function update(Source $source, array $data): void
    {
        $proxy = match (true) {
            (bool) ($data['remove_proxy'] ?? false) => ['proxy_url' => null],
            filled($data['proxy_url'] ?? null) => ['proxy_url' => $data['proxy_url']],
            default => [],
        };

        $source->update([
            'name' => $data['name'],
            'interval_minutes' => $data['interval_minutes'],
            'max_items' => $data['max_items'] ?: null,
            ...$proxy,
        ]);
    }

    /** Liga ou desliga a fonte; devolve se ficou ligada. */
    public function toggle(Source $source): bool
    {
        $source->forceFill(['enabled' => ! $source->enabled])->save();

        return $source->enabled;
    }

    /** As matérias já coletadas pela fonte ficam. */
    public function delete(Source $source): void
    {
        $source->delete();
    }

    /** Uma fonte só é rápido o bastante para rodar na própria requisição. */
    public function run(Source $source): CrawlRun
    {
        return $this->crawler->runSource($source, 'manual');
    }

    /**
     * Varre todas as fontes pela fila: leva minutos e estouraria o timeout da
     * requisição. Sem `$force`, respeita o intervalo de cada fonte.
     */
    public function runAll(bool $force): void
    {
        if (! Settings::bool('crawler.enabled')) {
            throw new ActionRefused('O crawler está desligado nas configurações.');
        }

        if (Cache::get('secmon:crawler') !== null) {
            throw new ActionRefused('Já existe uma coleta em andamento — acompanhe em Coletas.');
        }

        RunCrawler::dispatch($force);
    }

    /** Admin → Execuções: as coletas e os artigos que ainda esperam a análise. */
    public function runsPage(): array
    {
        return [
            'runs' => CrawlRun::query()->with('source')->latest('started_at')->paginate(40),
            'failures' => Article::query()
                ->whereIn('status', ['pending', 'failed'])
                ->latest('collected_at')
                ->limit(30)
                ->get(),
        ];
    }
}
