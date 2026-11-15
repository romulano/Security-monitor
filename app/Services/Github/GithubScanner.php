<?php

namespace App\Services\Github;

use App\Jobs\TriageGithubFinding;
use App\Models\GithubFinding;
use App\Models\GithubMonitor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A varredura diária de um monitoramento: roda as buscas (GithubQueries),
 * junta os resultados por arquivo e por repositório, mascara os segredos,
 * grava os achados e manda os novos (ou que mudaram) para a classificação da
 * IA. Depois confere se os achados que sumiram da busca ainda existem.
 */
class GithubScanner
{
    /** Tamanho máximo do trecho guardado por achado. */
    private const FRAGMENT_MAX = 1500;

    public function __construct(private GithubClient $github) {}

    /** @return array{queries: int, code: int, repos: int, results: int, new: int, changed: int, gone: int, skipped: list<string>} */
    public function scan(GithubMonitor $monitor): array
    {
        $startedAt = now();
        $monitor->forceFill(['scan_status' => 'running', 'last_log' => [], 'last_error' => null])->save();

        $stats = ['queries' => 0, 'code' => 0, 'repos' => 0, 'results' => 0, 'new' => 0, 'changed' => 0, 'gone' => 0, 'skipped' => []];
        $hits = [];
        $budget = ['code' => (int) config('secmon.github.max_code_queries'), 'repos' => (int) config('secmon.github.max_repo_queries')];
        $codeOk = $this->github->hasToken();
        $stopped = null;

        if (! $codeOk) {
            $stats['skipped'][] = 'busca em código: sem token do GitHub';
        }

        foreach (GithubQueries::for($monitor) as $query) {
            if ($stopped) {
                break;
            }

            $family = $query['type'] === 'code' ? 'code' : 'repos';

            if ($family === 'code' && ! $codeOk) {
                continue;
            }

            if ($budget[$family]-- <= 0) {
                $stats['skipped'][] = 'limite de buscas por dia: '.$query['q'];

                continue;
            }

            try {
                $found = $this->run($query, $monitor, $hits);
                $stats['queries']++;
                $stats[$family]++;
                $stats['results'] += $found;
                $monitor->appendLog(sprintf('%s %s → %d', $query['type'], $query['q'], $found));
            } catch (GithubRateLimited $e) {
                // O que faltou fica para a próxima varredura (no dia seguinte, ou no "Verificar agora").
                $stopped = $e->getMessage();
                $monitor->appendLog('parou: '.$e->getMessage());
            } catch (GithubUnavailable $e) {
                $codeOk = false;
                $stats['skipped'][] = 'busca em código: '.$e->getMessage();
                $monitor->appendLog('busca em código indisponível: '.$e->getMessage());
            } catch (Throwable $e) {
                $monitor->appendLog('erro em '.$query['q'].': '.Str::limit($e->getMessage(), 160));
                Log::warning('Busca do monitoramento do GitHub falhou', ['monitor' => $monitor->id, 'q' => $query['q'], 'error' => $e->getMessage()]);
            }
        }

        foreach ($hits as $fingerprint => $hit) {
            match ($this->save($monitor, $fingerprint, $hit)) {
                'new' => $stats['new']++,
                'changed' => $stats['changed']++,
                default => null,
            };
        }

        // Só dá para dizer que sumiu quando a busca rodou inteira.
        if (! $stopped && $codeOk) {
            $stats['gone'] = $this->recheckMissing($monitor, $startedAt);
        }

        if ($stopped) {
            $stats['skipped'][] = $stopped;
        }

        $monitor->forceFill([
            'scan_status' => 'done',
            'last_scan_at' => now(),
            // Parou no limite da API: tenta de novo em 1 h, em vez de esperar o dia seguinte.
            'next_scan_at' => $stopped ? now()->addHour() : now()->addHours((int) config('secmon.github.scan_every_hours', 24)),
            'stats' => $stats,
            'last_error' => $stopped,
        ])->save();

        return $stats;
    }

    /**
     * Roda uma busca e junta o que achou em `$hits` (um por arquivo ou repositório).
     *
     * @param  array<string, array>  $hits
     */
    private function run(array $query, GithubMonitor $monitor, array &$hits): int
    {
        return match ($query['type']) {
            'code' => $this->code($query, $monitor, $hits),
            'repos' => $this->repos($query, $monitor, $hits),
            'owner' => $this->owner($query, $monitor, $hits),
            'repo' => $this->direct($query, $monitor, $hits),
        };
    }

    private function code(array $query, GithubMonitor $monitor, array &$hits): int
    {
        $found = 0;

        foreach ($this->github->searchCode($query['q'])['items'] as $item) {
            $repo = (array) ($item['repository'] ?? []);
            $fragments = collect($item['text_matches'] ?? [])->pluck('fragment')->filter()->unique()->values();
            $path = (string) ($item['path'] ?? '');

            if (! isset($repo['full_name']) || $path === '' || ! $this->confirms($query, $fragments->implode("\n"), $path)) {
                continue;
            }

            $fingerprint = sha1('file|'.mb_strtolower($repo['full_name']).'|'.$path);
            $hit = $hits[$fingerprint] ?? [
                'family' => 'file', 'kind' => $query['kind'], 'terms' => [], 'fragments' => [],
                'repo' => $repo, 'path' => $path, 'url' => (string) ($item['html_url'] ?? $repo['html_url'] ?? ''),
            ];
            $hit['kind'] = self::graver($hit['kind'], $query['kind']);
            $hit['terms'] = array_values(array_unique([...$hit['terms'], $query['term']]));
            $hit['fragments'] = $fragments->merge($hit['fragments'])->unique()->take(4)->values()->all();
            $hits[$fingerprint] = $hit;
            $found++;
        }

        return $found;
    }

    /** O resultado traz mesmo o termo? A busca ignora pontuação; aqui ela vale. */
    private function confirms(array $query, string $fragment, string $path): bool
    {
        $haystack = mb_strtolower($fragment."\n".$path);

        return match ($query['kind']) {
            'email_leak' => preg_match('/[\w.+\-]+'.preg_quote(mb_strtolower($query['term']), '/').'\b/u', $haystack) === 1,
            // .env.example e parecidos são modelo: esperado num repositório público.
            'sensitive_file' => $query['term'] === '.env'
                ? str_starts_with(basename($path), '.env') && ! preg_match('/\.(example|sample|dist|template|tpl|defaults?)$/i', $path)
                : str_contains($haystack, 'private key-----'),
            default => str_contains($haystack, mb_strtolower($query['term'])),
        };
    }

    private function repos(array $query, GithubMonitor $monitor, array &$hits): int
    {
        $found = 0;
        $wanted = GithubQueries::normalize($query['term']);

        foreach ($this->github->searchRepos($query['q'])['items'] as $repo) {
            $fullName = (string) ($repo['full_name'] ?? '');

            if ($fullName === '' || $monitor->owns($fullName)) {
                continue;
            }

            $name = GithubQueries::normalize((string) ($repo['name'] ?? ''));
            $text = mb_strtolower($name.' '.($repo['description'] ?? ''));
            $match = $query['kind'] === 'private_repo'
                ? $name === $wanted
                : str_contains(str_replace('-', '', $text), str_replace('-', '', $wanted));

            if ($match) {
                $this->addRepo($hits, $query['kind'], $query['term'], $repo);
                $found++;
            }
        }

        return $found;
    }

    /** Um repositório marcado como privado responde em público? Então vazou. */
    private function direct(array $query, GithubMonitor $monitor, array &$hits): int
    {
        $repo = $this->github->repo($query['q']);

        if (! $repo || ($repo['private'] ?? false)) {
            return 0;
        }

        $this->addRepo($hits, 'private_repo', $query['term'], $repo, direct: true);

        return 1;
    }

    /**
     * Repositórios públicos da organização: o que apareceu desde a última
     * varredura vira achado. Na primeira, a lista só é guardada.
     */
    private function owner(array $query, GithubMonitor $monitor, array &$hits): int
    {
        $repos = $this->github->ownerRepos($query['q']);

        if ($repos === null) {
            $monitor->appendLog('organização '.$query['q'].' não existe no GitHub');

            return 0;
        }

        $key = mb_strtolower($query['q']);
        $known = $monitor->known_repos ?? [];
        $names = collect($repos)->pluck('full_name')->filter()->map(fn ($n) => mb_strtolower($n))->values()->all();
        $found = 0;

        if (array_key_exists($key, $known)) {
            $official = array_map('mb_strtolower', $monitor->public_repos ?? []);

            foreach ($repos as $repo) {
                $name = mb_strtolower((string) ($repo['full_name'] ?? ''));

                if ($name !== '' && ! in_array($name, $known[$key], true) && ! in_array($name, $official, true)) {
                    $this->addRepo($hits, 'org_new_repo', $query['q'], $repo);
                    $found++;
                }
            }
        }

        // A lista acumula: repositório apagado e recriado não volta a ser "novo".
        $known[$key] = array_values(array_unique([...($known[$key] ?? []), ...$names]));
        $monitor->known_repos = $known;

        return $found;
    }

    private function addRepo(array &$hits, string $kind, string $term, array $repo, bool $direct = false): void
    {
        $fingerprint = sha1('repo|'.mb_strtolower((string) $repo['full_name']));
        $hit = $hits[$fingerprint] ?? ['family' => 'repo', 'kind' => $kind, 'terms' => [], 'repo' => $repo, 'path' => null, 'url' => (string) ($repo['html_url'] ?? ''), 'direct' => false];
        $hit['kind'] = self::graver($hit['kind'], $kind);
        $hit['terms'] = array_values(array_unique([...$hit['terms'], $term]));
        $hit['direct'] = $hit['direct'] || $direct;
        $hits[$fingerprint] = $hit;
    }

    /** Dos dois tipos, o mais grave (a ordem de GithubFinding::KINDS). */
    private static function graver(string $a, string $b): string
    {
        $order = array_keys(GithubFinding::KINDS);

        return array_search($a, $order, true) <= array_search($b, $order, true) ? $a : $b;
    }

    /** Grava o achado; devolve `new`, `changed` ou `same`. Novo e mudado (ainda não enviado) vão para a classificação. */
    private function save(GithubMonitor $monitor, string $fingerprint, array $hit): string
    {
        $finding = $monitor->findings()->firstOrNew(['fingerprint' => $fingerprint]);
        $repo = $hit['repo'];
        $own = $monitor->owns((string) $repo['full_name']);
        $terms = array_values(array_unique([...($finding->terms ?? []), ...$hit['terms']]));

        if ($hit['family'] === 'file') {
            $masked = SecretMasker::mask(implode("\n…\n", $hit['fragments']), $monitor->combined('key_names'), $monitor->combined('token_prefixes'));
            $fragment = Str::limit($masked['text'], self::FRAGMENT_MAX);
            $secrets = $masked['secrets'];
            $evaluated = GithubSignals::file(['kind' => $hit['kind'], 'terms' => $terms, 'path' => $hit['path'], 'repo' => $repo, 'own' => $own, 'secrets' => $secrets], $monitor);
        } else {
            $fragment = null;
            $secrets = [];
            $evaluated = GithubSignals::repo(['kind' => $hit['kind'], 'terms' => $terms, 'repo' => $repo, 'own' => $own, 'direct' => $hit['direct'] ?? false], $monitor);
        }

        $hash = sha1($hit['kind'].'|'.implode(',', $terms).'|'.$fragment);
        $isNew = ! $finding->exists;
        $changed = ! $isNew && $finding->content_hash !== $hash;

        $finding->fill([
            'kind' => $isNew ? $hit['kind'] : self::graver($finding->kind, $hit['kind']),
            'terms' => $terms,
            'repo' => (string) $repo['full_name'],
            'repo_owner' => $repo['owner']['login'] ?? explode('/', (string) $repo['full_name'])[0],
            'repo_owner_type' => $repo['owner']['type'] ?? null,
            'repo_description' => isset($repo['description']) ? Str::limit((string) $repo['description'], 500) : $finding->repo_description,
            'repo_fork' => (bool) ($repo['fork'] ?? false),
            'repo_stars' => (int) ($repo['stargazers_count'] ?? $finding->repo_stars ?? 0),
            'repo_created_at' => isset($repo['created_at']) ? Carbon::parse($repo['created_at']) : $finding->repo_created_at,
            'repo_pushed_at' => isset($repo['pushed_at']) ? Carbon::parse($repo['pushed_at']) : $finding->repo_pushed_at,
            'path' => $hit['path'],
            'html_url' => $hit['url'],
            'fragment' => $fragment,
            'secrets' => $secrets,
            'content_hash' => $hash,
            'signals' => $evaluated['signals'],
            'auto_level' => $evaluated['level'],
            'last_seen_at' => now(),
            'gone_at' => null,
        ]);

        if ($isNew) {
            $finding->fill(['first_seen_at' => now(), 'status' => 'triage']);
        } elseif ($changed) {
            $finding->changed_at = now();

            // Mudou e ainda não tinha saído (nem foi avaliado pelo cliente): passa de novo pelas duas etapas.
            if (in_array($finding->status, ['discarded', 'below_level', 'held', 'skipped', 'failed'], true) && $finding->feedback === null) {
                $finding->fill(['status' => 'triage', 'note' => null]);
            }
        }

        $finding->save();

        if ($finding->status === 'triage' && ($isNew || $changed)) {
            TriageGithubFinding::dispatch($finding->id);
        }

        return $isNew ? 'new' : ($changed ? 'changed' : 'same');
    }

    /**
     * Achados abertos que a busca não trouxe desta vez: o arquivo (ou o
     * repositório) ainda existe? A busca do GitHub pode demorar a indexar,
     * então só conta como removido quando a própria API diz que sumiu.
     */
    private function recheckMissing(GithubMonitor $monitor, Carbon $startedAt): int
    {
        $gone = 0;

        $missing = $monitor->findings()
            ->whereNull('gone_at')
            ->where('last_seen_at', '<', $startedAt)
            ->whereNotIn('status', ['discarded', 'triage'])
            ->byLevel()
            ->limit((int) config('secmon.github.recheck_gone', 20))
            ->get();

        foreach ($missing as $finding) {
            $exists = $finding->isRepo()
                ? ($this->safely(fn () => $this->github->repo($finding->repo)) !== null)
                : $this->github->fileExists($finding->repo, (string) $finding->path);

            if ($exists === false) {
                $finding->forceFill(['gone_at' => now()])->save();
                $gone++;
            }
        }

        return $gone;
    }

    private function safely(callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable) {
            return true; // sem resposta não dá para dizer que sumiu
        }
    }
}
