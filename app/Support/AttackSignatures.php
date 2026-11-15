<?php

namespace App\Support;

/**
 * Admin → Acessos: a cara de ataque de um acesso, pelo endereço, pelo navegador
 * e pelo Referer que ele mandou (e pelos nomes dos cabeçalhos, quando estão na
 * mão). É assinatura conhecida de ferramenta e de robô de varredura, não prova:
 * diz o que a requisição tentou, não se deu certo (quase tudo é 404).
 *
 * O endereço é decodificado uma vez, em minúsculas e cortado em 1.000
 * caracteres, e as regex não têm quantificador aninhado (o custo fica limitado
 * mesmo com entrada feita para travar). Vale a primeira categoria da ordem de
 * PATTERNS que casar. O valor do `q` é a busca de notícias de segurança, onde
 * "union select" e "/etc/passwd" são buscas de verdade: ali só casa a sintaxe
 * forte de SQL e de script (STRONG).
 */
final class AttackSignatures
{
    /** Código => rótulo. As três últimas são de contexto, para o registro do que o site recusa. */
    public const CATEGORIES = [
        'cms' => 'WordPress e outros CMS',
        'arquivo_sensivel' => 'arquivo sensível',
        'painel' => 'painel de serviço',
        'php_laravel' => 'PHP/Laravel',
        'java' => 'Java/Spring',
        'cgi_iot' => 'CGI/roteador',
        'traversal' => 'subida de pasta',
        'sqli' => 'injeção de SQL',
        'xss_ssti' => 'injeção de script',
        'ssrf_redirect' => 'SSRF/redirecionamento',
        'injecao_cabecalho' => 'injeção em cabeçalho',
        'api_recon' => 'sondagem da API',
        'metodo' => 'método incomum',
        'area_admin' => 'área do admin',
        'autenticacao' => 'login e cadastro',
    ];

    /** As que levam carga de ataque (não só procuram o que existe): o IP que as manda é atacante em Admin → Acessos. */
    public const PAYLOADS = ['traversal', 'sqli', 'xss_ssti', 'injecao_cabecalho', 'ssrf_redirect', 'php_laravel', 'java', 'cgi_iot'];

    public const MAX_INPUT = 1000;

    /**
     * Na ordem de prioridade: categoria => [onde, regex]. Onde: `full` (caminho + query sem o `q`), `path`
     * (só o caminho) ou `query` (só a query, sem o `q`). Do catálogo do red-team, com o `+` lido como espaço.
     */
    private const PATTERNS = [
        ['injecao_cabecalho', 'full', '~[\r\n]|%0d|%0a~'],
        ['traversal', 'full', '~(?:\.\.|%2e%2e|%252e%252e|\.%2e|%2e\.|%c0%ae|%u002e)(?:/|\\\\|%2f|%5c|%252f|;)|/etc/(?:passwd|shadow|hosts)|(?:win|boot)\.ini|/proc/self/|%00|\x00|file:/~'],
        ['sqli', 'full', '~\bunion\b[\s\S]{0,40}\bselect\b|\bselect\b[\s\S]{0,60}\bfrom\b|\b(?:sleep|benchmark|pg_sleep)\s*\(|waitfor\s+delay|\b(?:or|and)\b\s+[\'"]?\d+[\'"]?\s*[=<>]|\'\s*(?:or|and)\s+\'|(?:--|#)\s*-?\s*$|/\*[\s\S]{0,40}\*/|information_schema|extractvalue|updatexml|;\s*(?:drop|insert|update|delete|select)\b|0x[0-9a-f]{8,}|\bchar\s*\(\d~'],
        ['xss_ssti', 'full', '~<\s*/?\s*(?:script|img|svg|iframe|body|details|object|embed|math|video|audio|style)\b|javascript\s*:|\bon[a-z]{3,20}\s*=|\b(?:alert|prompt|confirm)\s*\(|document\.(?:cookie|domain|location)|%3c\s*script|\{\{[\s\S]{0,20}\}\}|\$\{[\s\S]{0,20}\}|<%=~'],
        ['php_laravel', 'path', '~^/(?:_ignition/|telescope|horizon|_debugbar|storage/(?:logs|framework)/|bootstrap/cache|vendor/|artisan$|laravel\.log|livewire/|sanctum/|nova(?:/|$)|filament|_profiler|app_dev\.php|php-cgi)~'],
        ['php_laravel', 'query', '~(?:^|&)(?:xdebug_session(?:_start)?|xdebug_profile|_debugbar)=|(?:php|data|expect|phar|zip|glob)://|allow_url_include|auto_prepend_file|(?:^|[&\s])[-\xad]d\s|%add|invokefunction|call_user_func|think\\\\app~'],
        ['java', 'full', '~^/(?:actuator(?:/|$)|jolokia|env$|heapdump|swagger(?:-ui)?|v[23]/api-docs|api-docs|struts|wls-wsat|_async/|console/login|ws_utc|axis2|cfide|jasperserver|plugins/servlet|nifi|druid/)|\.(?:action|do|jsp|jspx|aspx?|cfm)(?:$|\?)|class\.module\.classloader|%24%7b|\$\{\(?#~'],
        ['cgi_iot', 'full', '~^/(?:cgi-bin/|hnap1|boaform|gponform|setup\.cgi|shell\?|tmunblock\.cgi|picsdesc\.xml|ctrlt/|device\.rsp|language/swedish)|\.(?:cgi|pl|sh)(?:$|\?)|(?:wget|curl|tftp|busybox|chmod)(?:\s|%20|\$\{ifs\})|/bin/(?:sh|bash)|\$\{ifs\}~'],
        ['cms', 'full', '~^/(?:[a-z0-9_-]+/)?(?:wp-(?:admin|login|content|includes|json|config|cron|signup|trackback)|xmlrpc\.php|wordpress/)|[?&](?:author=\d|rest_route=)|^/(?:administrator|components/com_|modules/mod_|configuration\.php|core/(?:install|changelog)|sites/default/|changelog\.txt|magento_version|downloader/|app/etc/|typo3|umbraco|bitrix|ghost/api)~'],
        ['arquivo_sensivel', 'path', '~(?:^|/)\.(?:env|git|svn|hg|bzr|ds_store|htaccess|htpasswd|aws|ssh|docker|vscode|idea|npmrc|kube|bash_history)(?:[./]|$)|(?:^|/)(?:web\.config|composer\.(?:json|lock)|package(?:-lock)?\.json|yarn\.lock|dockerfile|docker-compose\.ya?ml|id_[rd]sa|credentials|secrets?\.(?:json|ya?ml)|config\.(?:json|ya?ml|inc)|env)$|\.(?:sql|sql\.gz|zip|tar|tar\.gz|tgz|7z|rar|bak|old|orig|save|swp|backup|dump|db|sqlite3?|pem|key|p12|pfx|ini|conf)$|\~$~'],
        ['painel', 'path', '~^/(?:php[-_]?my[-_]?admin|pma|myadmin|mysqladmin|dbadmin|sqladmin|adminer|phppgadmin|pgadmin|server-(?:status|info)|nginx_status|manager/html|jmx-console|web-console|solr/|kibana|_cat/|_cluster/|_nodes|grafana|prometheus|metrics$|webmin|cpanel|whm|plesk|portainer|jenkins|hudson|nacos|druid|zabbix|rabbitmq|traefik|console/?$|remote/(?:fgt_lang|login)|dana-na|global-protect|vpn/|\+cscoe\+|tmui/|owa/|ecp/|autodiscover/)~'],
        ['ssrf_redirect', 'full', '~[?&](?:url|uri|u|redirect(?:_uri|_url|_to)?|return(?:_?url|_?to)?|next|dest(?:ination)?|continue|goto|target|callback|forward|out|link|image_?url|feed|host|proxy|file|path|load|domain|site)=(?:https?(?::|%3a)|//|%2f%2f|/\\\\|\\\\\\\\|%5c|169\.254\.169\.254|metadata\.google|localhost|127\.|0x7f|\[::|gopher|dict|file|ftp|ldap)~'],
        // A paginação do próprio site passa de 1.000 páginas: só o número absurdo conta.
        ['api_recon', 'full', '~^/(?:api(?:/v\d+)?/(?:swagger|openapi|docs|users?|admin|graphql|debug|config|health|env)|graphql|graphiql|api/?(?:$|\?))|[?&](?:per_page|limit|size)=\d{4,}|[?&]page=\d{6,}~'],
    ];

    /** No valor do `q`: só a sintaxe que não aparece numa busca de notícia. */
    private const STRONG = [
        'sqli' => '~\'\s*(?:or|and|union)\b|[\'")\d]\s*(?:--|#)\s*-?\s*$|\b(?:sleep|pg_sleep|benchmark)\s*\(~',
        'xss_ssti' => '~<\s*script|\bon[a-z]{3,20}\s*=|javascript\s*:~',
    ];

    /** No navegador e no Referer: expressão do Log4j/OGNL, Shellshock, script e SQL. */
    private const HEADER = '~\$\{(?:jndi|\$\{|lower:|upper:|env:|sys:|date:|::-[a-z]|base64:)|%24%7b(?:jndi|%24)|%\{\(#|#\{\d|\(\)\s*\{|<\s*script|\'\s*(?:or|and)\s~';

    /** Cabeçalhos que só servem para enganar o roteamento atrás de proxy. */
    private const HEADER_NAMES = ['x-original-url', 'x-rewrite-url'];

    /** Só a área do Security Monitor que não é painel estrangeiro nem sondagem: o /admin e a API de notícias. */
    private const OURS = '~^/(?:admin(?:/|$|\?)|api/news(?:/|$|\?))~';

    /** @param  list<string>  $headerNames  os nomes dos cabeçalhos da requisição (nunca os valores) */
    public static function classify(string $uri, ?string $userAgent = null, ?string $referer = null, array $headerNames = []): ?string
    {
        foreach ([$userAgent, $referer] as $header) {
            if ($header !== null && self::matches(self::HEADER, strtolower(substr($header, 0, self::MAX_INPUT)))) {
                return 'injecao_cabecalho';
            }
        }

        if (array_intersect(array_map('strtolower', $headerNames), self::HEADER_NAMES) !== []) {
            return 'injecao_cabecalho';
        }

        [$path, $query, $search] = self::split($uri);
        $full = $path.($query !== '' ? '?'.$query : '');
        $ours = self::matches(self::OURS, $full);

        foreach (self::PATTERNS as [$category, $where, $pattern]) {
            if ($ours && in_array($category, ['painel', 'api_recon'], true)) {
                continue;
            }

            $subject = ['path' => $path, 'query' => $query][$where] ?? $full;

            if (self::matches($pattern, $subject) || (isset(self::STRONG[$category]) && $search !== '' && self::matches(self::STRONG[$category], $search))) {
                return $category;
            }
        }

        return null;
    }

    public static function label(?string $category): ?string
    {
        return $category === null ? null : (self::CATEGORIES[$category] ?? $category);
    }

    /**
     * O caminho e a query decodificados uma vez, cada par por si (o `%26` de um valor não vira outro par),
     * e os valores do `q` à parte.
     *
     * @return array{string, string, string}
     */
    private static function split(string $uri): array
    {
        [$path, $query] = array_pad(explode('?', substr($uri, 0, self::MAX_INPUT), 2), 2, '');
        $pairs = $search = [];

        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            $decoded = strtolower(urldecode($pair));

            if (preg_match('/^q(?:\[[^\]]*\])?=/', $decoded) === 1) {
                $search[] = substr($decoded, strpos($decoded, '=') + 1);
            } else {
                $pairs[] = $decoded;
            }
        }

        return [strtolower(rawurldecode($path)), implode('&', $pairs), implode(' ', $search)];
    }

    /** O preg_match que falha (limite de retrocesso) conta como "não casou". */
    private static function matches(string $pattern, string $subject): bool
    {
        return preg_match($pattern, $subject) === 1;
    }
}
