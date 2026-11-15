<?php

namespace App\Services\Github;

use App\Models\GithubMonitor;

/**
 * As buscas de uma varredura, montadas a partir da entrevista e do que a IA
 * tirou do texto "Adicional". Cada uma diz o tipo de achado que gera e o termo
 * que precisa estar no resultado (a busca do GitHub ignora pontuação, então
 * `fm_site_br` também acha "fm site br": o termo exato é conferido depois).
 *
 * Tipos: `code` (busca em código, exige token), `repos` (busca de
 * repositórios), `owner` (repositórios públicos de uma organização) e `repo`
 * (um repositório pelo nome, para saber se ficou público).
 */
final class GithubQueries
{
    /** Nomes que existem em qualquer empresa: buscar no GitHub inteiro só traria ruído. */
    private const GENERIC_REPOS = ['api', 'app', 'apps', 'backend', 'frontend', 'front', 'back', 'site', 'web', 'www', 'core', 'infra', 'docs', 'mobile',
        'server', 'admin', 'android', 'ios', 'scripts', 'config', 'terraform', 'ansible', 'deploy', 'website', 'landing', 'blog', 'test', 'tests'];

    /** @return list<array{type: string, kind: string, q: string, term: string}> */
    public static function for(GithubMonitor $monitor): array
    {
        $queries = [];
        $orgs = array_slice($monitor->orgs ?? [], 0, 3);
        $add = function (string $type, string $kind, string $q, string $term) use (&$queries, $monitor) {
            if ($monitor->wantsKind($kind) && mb_strlen($q) <= 256) {
                $queries[] = compact('type', 'kind', 'q', 'term');
            }
        };

        // Segredos e o que só existe dentro da empresa: busca em código.
        foreach ($monitor->combined('key_names') as $name) {
            $add('code', 'key_leak', '"'.$name.'"', $name);
        }

        foreach ($monitor->combined('token_prefixes') as $prefix) {
            $add('code', 'token_prefix', '"'.$prefix.'"', $prefix);
        }

        foreach ($monitor->combined('hostnames') as $host) {
            $add('code', 'hostname_leak', '"'.$host.'"', $host);
        }

        foreach (array_slice($monitor->email_domains ?? [], 0, 3) as $domain) {
            $add('code', 'email_leak', '"@'.$domain.'" password OR senha OR passwd', '@'.$domain);
        }

        foreach (array_slice($monitor->extra('terms'), 0, 5) as $term) {
            $add('code', 'extra_term', '"'.$term.'"', $term);
        }

        // A busca em código exige pelo menos um termo além do filtro de dono e de arquivo.
        foreach ($orgs as $org) {
            $add('code', 'sensitive_file', 'password OR secret OR token OR key filename:.env user:'.$org, '.env');
            $add('code', 'sensitive_file', '"PRIVATE KEY" user:'.$org, 'PRIVATE KEY');
        }

        // Repositórios privados: existe público com esse nome na própria organização? E em outra conta?
        foreach ($monitor->combined('private_repos') as $repo) {
            [$owner, $name] = str_contains($repo, '/') ? explode('/', $repo, 2) : [null, $repo];

            foreach ($owner ? [$owner] : $orgs as $login) {
                $add('repo', 'private_repo', $login.'/'.$name, $name);
            }

            if (self::distinctive($name)) {
                $add('repos', 'private_repo', $name.' in:name', $name);
            }
        }

        foreach ($orgs as $org) {
            $add('owner', 'org_new_repo', $org, $org);
        }

        // A marca no nome ou na descrição: "Fatal Model" vira fatalmodel e fatal-model.
        foreach (array_slice($monitor->brands ?? [], 0, 5) as $brand) {
            foreach (self::brandVariants($brand) as $variant) {
                $add('repos', 'brand_repo', $variant.' in:name,description', $variant);
            }
        }

        return $queries;
    }

    /** @return list<string> */
    public static function brandVariants(string $brand): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($brand)), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === [] || mb_strlen(implode('', $words)) < 4) {
            return [];
        }

        return array_values(array_unique(count($words) > 1 ? [implode('', $words), implode('-', $words)] : [$words[0]]));
    }

    /** Forma comparável de um nome de repositório: minúsculas, `_` e `.` como `-`. */
    public static function normalize(string $name): string
    {
        return trim(preg_replace('/[_.\s]+/', '-', mb_strtolower($name)), '-');
    }

    private static function distinctive(string $name): bool
    {
        $name = self::normalize($name);

        return mb_strlen($name) >= 6 && ! in_array($name, self::GENERIC_REPOS, true);
    }
}
