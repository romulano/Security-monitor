<?php

namespace App\Services;

use App\Models\NewsCase;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O /sitemap.xml: as páginas fixas e as matérias publicadas, lidas do banco
 * (sem o Elasticsearch), cada uma com a data da última mudança dela. Fica de
 * fora a matéria escondida do feed (410) e a apagada, pela fusão de matérias (o
 * link dela redireciona para a nova, que está no mapa) ou pelo admin. A busca
 * (?q=), a API, o admin, as áreas com login e os links assinados nunca entram.
 *
 * O arquivo pronto fica no cache. Quando uma matéria entra, muda a data ou sai
 * do ar, forget() troca a versão e a próxima leitura refaz o arquivo: sem cron.
 * Acima de `secmon.sitemap.per_file` matérias, o /sitemap.xml vira o índice das
 * partes (/sitemap-1.xml, /sitemap-2.xml...), e as páginas fixas ficam na 1ª.
 *
 * Os endereços saem do APP_URL, nunca do host da requisição: a conferência do
 * deploy (pelo IP interno) não deixa no cache um mapa com endereço de dentro.
 */
class Sitemap
{
    /** A versão do conteúdo: cada arquivo guardado leva a sua e só vale enquanto ela for a atual. */
    private const VERSION = 'sitemap:versao';

    /** Cada arquivo numa chave fixa: refazer sobrescreve, e nada se acumula no cache. */
    private const FILE = 'sitemap:arquivo:';

    /**
     * As páginas fixas e públicas, na ordem do arquivo. Verdadeiro: a página
     * muda com as matérias (o feed e o painel), e a data dela é a da mais nova.
     */
    private const PAGES = ['feed.index' => true, 'feed.dashboard' => true, 'about' => false, 'terms' => false, 'privacy' => false];

    /**
     * A última mudança de verdade da matéria: a abertura, a fonte nova com
     * informação, a fusão, a explicação da falha. O `updated_at` não serve: o
     * CaseIndex o renova a cada indexação (e o reindex, o de todas de uma vez).
     */
    private const LASTMOD = "greatest(created_at at time zone 'UTC', latest_at, consolidated_at, vulnerability_explained_at)";

    private const XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** A próxima leitura refaz os arquivos. */
    public function forget(): void
    {
        Cache::forever(self::VERSION, (string) Str::ulid());
    }

    /**
     * O /sitemap.xml (sem $part) ou uma parte do índice. Nulo: a parte não
     * existe (o site cabe num arquivo só, ou o número passa do último).
     *
     * @return array{xml: string, etag: string, lastmod: ?string, parts: int}|null
     */
    public function file(?int $part = null): ?array
    {
        $main = $this->cached('principal', fn () => $this->main());

        if ($part === null) {
            return $main;
        }

        // Só o número que o índice lista vira chave no cache: um robô que pede /sitemap-99999.xml não enche a tabela.
        if ($part < 1 || $part > $main['parts']) {
            return null;
        }

        return $this->cached((string) $part, fn () => $this->part($part));
    }

    /** Quantos endereços o mapa tem agora (Admin → Configurações). */
    public function count(): int
    {
        return count(self::PAGES) + $this->published()->count();
    }

    /** @param  Closure(): array{xml: string, etag: string, lastmod: ?string, parts: int}  $build */
    private function cached(string $name, Closure $build): array
    {
        $version = Cache::rememberForever(self::VERSION, fn () => (string) Str::ulid());
        $entry = Cache::get(self::FILE.$name);

        if (is_array($entry) && ($entry['version'] ?? null) === $version) {
            return $entry['file'];
        }

        // Se a versão mudar enquanto o arquivo é montado, ele vai com a antiga e a leitura seguinte o refaz.
        $file = $build();
        Cache::put(self::FILE.$name, ['version' => $version, 'file' => $file], now()->addMinutes((int) config('secmon.sitemap.cache_minutes')));

        return $file;
    }

    private function main(): array
    {
        $perFile = $this->perFile();

        if ($this->published()->count() <= $perFile) {
            return $this->urlset(withPages: true, cases: $this->rows($this->published()));
        }

        $rows = DB::query()
            ->fromSub($this->published()->selectRaw('row_number() over (order by id) as n, '.self::LASTMOD.' as at'), 'c')
            ->selectRaw('(n - 1) / ? + 1 as part, '.self::iso('max(at)').' as lastmod', [$perFile])
            ->groupByRaw('1')
            ->orderByRaw('1')
            ->get();

        $base = $this->base();
        $newest = $rows->max('lastmod');
        // A 1ª parte leva as páginas fixas, e o feed e o painel mudam com a matéria mais nova, esteja ela onde estiver.
        $lines = $rows->map(fn ($row) => self::entry('sitemap', $base.route('sitemap.part', ['part' => $row->part], false), (int) $row->part === 1 ? $newest : $row->lastmod))->all();

        return $this->document('sitemapindex', $lines, $newest, $rows->count());
    }

    private function part(int $part): array
    {
        $perFile = $this->perFile();

        return $this->urlset(withPages: $part === 1, cases: $this->rows($this->published()->orderBy('id')->offset(($part - 1) * $perFile)->limit($perFile)));
    }

    /** @return iterable<object{uuid: string, lastmod: ?string}> */
    private function rows(Builder $query): iterable
    {
        // Ordem estável (a do id): numa lista dividida, a matéria nova entra na última parte e as outras não mudam.
        return $query->select('uuid')->selectRaw(self::iso(self::LASTMOD).' as lastmod')->reorder('id')->cursor();
    }

    /** @param  iterable<object{uuid: string, lastmod: ?string}>  $cases */
    private function urlset(bool $withPages, iterable $cases): array
    {
        $base = $this->base();
        $lines = [];
        $latest = null;

        foreach ($cases as $case) {
            $lines[] = self::entry('url', $base.route('feed.show', $case->uuid, false), $case->lastmod);

            if ($case->lastmod !== null && $case->lastmod > (string) $latest) {
                $latest = $case->lastmod;
            }
        }

        if ($withPages) {
            // A matéria mais nova de todas, não só a desta parte: o feed e o painel mostram ela.
            $latest = $this->published()->selectRaw(self::iso('max('.self::LASTMOD.')').' as lastmod')->value('lastmod');
            $pages = array_map(fn (string $route, bool $followsCases) => self::entry('url', $base.route($route, [], false), $followsCases ? $latest : null), array_keys(self::PAGES), self::PAGES);
            $lines = [...$pages, ...$lines];
        }

        return $this->document('urlset', $lines, $latest, 0);
    }

    /** @param  list<string>  $lines */
    private function document(string $root, array $lines, ?string $lastmod, int $parts): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<'.$root.' xmlns="'.self::XMLNS.'">'."\n"
            .implode("\n", $lines).($lines === [] ? '' : "\n")
            .'</'.$root.'>'."\n";

        return ['xml' => $xml, 'etag' => md5($xml), 'lastmod' => $lastmod, 'parts' => $parts];
    }

    /** Um <url> da lista ou um <sitemap> do índice. */
    private static function entry(string $tag, string $loc, ?string $lastmod): string
    {
        return '<'.$tag.'><loc>'.self::escape($loc).'</loc>'.($lastmod ? '<lastmod>'.$lastmod.'</lastmod>' : '').'</'.$tag.'>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** A data no formato do protocolo (W3C Datetime), em UTC, já pelo banco: sem um Carbon por linha. */
    private static function iso(string $expression): string
    {
        return 'to_char(('.$expression.") at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"+00:00\"')";
    }

    private function published(): Builder
    {
        return NewsCase::query()->where('is_published', true)->toBase();
    }

    private function perFile(): int
    {
        return max(1, (int) config('secmon.sitemap.per_file'));
    }

    private function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
