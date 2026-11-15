<?php

namespace App\Services\Github;

use App\Models\GithubMonitor;
use Illuminate\Support\Carbon;

/**
 * O que a varredura percebe sozinha num achado (os sinais que a tela e a IA
 * mostram) e o nível pelas regras, que vale quando a classificação da IA está
 * desligada ou fora do ar.
 */
final class GithubSignals
{
    /** Palavras de golpe num repositório com a marca. */
    private const SCAM_WORDS = ['phishing', 'phish', 'login', 'clone', 'scam', 'fake', 'stealer', 'grabber', 'kit', 'panel', 'checker', 'combo',
        'leak', 'leaked', 'dump', 'cracked', 'crack', 'bypass', 'spoof', 'otp', 'bruteforce', 'brute'];

    /** Palavras de automação: robôs, raspagem e clientes não oficiais do serviço. */
    private const BOT_WORDS = ['bot', 'scraper', 'scraping', 'scrap', 'crawler', 'spider', 'unofficial', 'wrapper', 'sdk', 'api', 'client', 'automation'];

    /** Arquivo de exemplo, teste ou documentação: o valor ali raramente é real. */
    private const EXAMPLE_PATH = '/(^|[\/._-])(examples?|samples?|tests?|spec|fixtures?|mocks?|docs?|demo|template|dist)([\/._-]|$)/i';

    /**
     * @param  array{kind: string, terms: list<string>, path: ?string, repo: array, own: bool, secrets: list<array>}  $hit
     * @return array{signals: list<array{key: string, label: string}>, level: string}
     */
    public static function file(array $hit, GithubMonitor $monitor): array
    {
        $signals = [];
        $path = (string) $hit['path'];
        $base = basename($path);
        $example = preg_match(self::EXAMPLE_PATH, $path) === 1;
        $env = str_starts_with($base, '.env') && ! $example;
        $secrets = collect($hit['secrets']);
        $real = $secrets->where('looks_real', true);
        $ownReal = $real->whereIn('source', ['client_key', 'prefix', 'private_key']);

        foreach ($real->take(3) as $secret) {
            $signals[] = ['key' => 'real', 'label' => sprintf('Valor com cara de real em %s: %s (%d caracteres)', $secret['name'], $secret['masked'], $secret['length'])];
        }

        if ($secrets->where('kind', 'literal')->where('looks_real', false)->isNotEmpty()) {
            $signals[] = ['key' => 'literal', 'label' => 'Valor escrito no código, curto ou pouco aleatório'];
        }

        if ($ref = $secrets->firstWhere('kind', 'reference')) {
            $signals[] = ['key' => 'reference', 'label' => 'Só referência a variável de ambiente em '.$ref['name'].', sem o valor'];
        }

        if ($secrets->where('kind', 'placeholder')->isNotEmpty()) {
            $signals[] = ['key' => 'placeholder', 'label' => 'Valor de exemplo ou vazio'];
        }

        if ($env) {
            $signals[] = ['key' => 'env', 'label' => 'Arquivo '.$base.' (configuração real, não exemplo)'];
        }

        if ($example) {
            $signals[] = ['key' => 'example', 'label' => 'Arquivo de exemplo, teste ou documentação'];
        }

        if ($hit['kind'] === 'email_leak') {
            $signals[] = ['key' => 'email', 'label' => 'E-mail corporativo perto de "password" ou "senha"'];
        }

        $signals[] = $hit['own']
            ? ['key' => 'own', 'label' => 'Em repositório público da sua organização']
            : ['key' => 'third', 'label' => 'Em repositório de terceiro ('.($hit['repo']['owner']['login'] ?? '?').')'];

        if ($hit['repo']['fork'] ?? false) {
            $signals[] = ['key' => 'fork', 'label' => 'O repositório é um fork'];
        }

        $level = match (true) {
            $ownReal->isNotEmpty() && ! $example => 'critical',
            $real->isNotEmpty() && ($env || $hit['kind'] === 'sensitive_file') => 'critical',
            $real->isNotEmpty() && ! $example => 'high',
            $env || in_array($hit['kind'], ['sensitive_file', 'email_leak'], true) && ! $example => 'high',
            $hit['kind'] === 'hostname_leak' && ! $example => 'medium',
            $example || $secrets->isNotEmpty() && $secrets->every(fn ($s) => $s['kind'] !== 'literal') => 'low',
            default => 'medium',
        };

        return ['signals' => $signals, 'level' => $level];
    }

    /**
     * @param  array{kind: string, terms: list<string>, repo: array, own: bool, direct?: bool}  $hit
     * @return array{signals: list<array{key: string, label: string}>, level: string}
     */
    public static function repo(array $hit, GithubMonitor $monitor): array
    {
        $repo = $hit['repo'];
        $signals = [];
        $text = mb_strtolower(($repo['name'] ?? '').' '.($repo['description'] ?? '').' '.implode(' ', (array) ($repo['topics'] ?? [])));
        $scam = array_values(array_filter(self::SCAM_WORDS, fn ($w) => preg_match('/\b'.$w.'\b/', $text)));
        $bots = array_values(array_filter(self::BOT_WORDS, fn ($w) => preg_match('/\b'.$w.'\b/', $text)));
        $created = isset($repo['created_at']) ? Carbon::parse($repo['created_at']) : null;
        $pushed = isset($repo['pushed_at']) ? Carbon::parse($repo['pushed_at']) : null;

        if ($hit['kind'] === 'private_repo') {
            $signals[] = ($hit['direct'] ?? false)
                ? ['key' => 'private_public', 'label' => 'Repositório que você marcou como privado está público na sua organização']
                : ['key' => 'private_name', 'label' => 'Mesmo nome de um repositório privado seu ('.implode(', ', $hit['terms']).'), em conta de terceiro'];
        }

        if ($hit['kind'] === 'org_new_repo') {
            $signals[] = ['key' => 'new_public', 'label' => 'Apareceu público na sua organização desde a última varredura'];
        }

        if ($hit['kind'] === 'brand_repo') {
            $signals[] = ['key' => 'brand', 'label' => 'Usa a marca: '.implode(', ', $hit['terms'])];
        }

        if ($scam) {
            $signals[] = ['key' => 'scam', 'label' => 'Palavras de golpe: '.implode(', ', array_slice($scam, 0, 4))];
        }

        if ($bots) {
            $signals[] = ['key' => 'bot', 'label' => 'Automação ou cliente não oficial: '.implode(', ', array_slice($bots, 0, 4))];
        }

        if ($repo['fork'] ?? false) {
            $signals[] = ['key' => 'fork', 'label' => 'É um fork'];
        }

        if ($created && $created->gt(now()->subDays(30))) {
            $signals[] = ['key' => 'recent', 'label' => 'Criado há '.max(1, (int) $created->diffInDays(now(), true)).' dia(s)'];
        } elseif ($pushed && $pushed->lt(now()->subYears(2))) {
            $signals[] = ['key' => 'stale', 'label' => 'Parado desde '.$pushed->format('m/Y')];
        }

        if (($repo['stargazers_count'] ?? 0) >= 10) {
            $signals[] = ['key' => 'stars', 'label' => $repo['stargazers_count'].' estrelas'];
        }

        $level = match ($hit['kind']) {
            'private_repo' => ($hit['direct'] ?? false) ? 'critical' : 'high',
            'org_new_repo' => 'medium',
            default => match (true) {
                $scam !== [] => 'high',
                $bots !== [] => 'medium',
                default => 'low',
            },
        };

        return ['signals' => $signals, 'level' => $level];
    }
}
