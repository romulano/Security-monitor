<?php

namespace App\Support;

/**
 * De quem é o robô e para que ele lê o site, pelo nome que ele declara no
 * navegador (o User-Agent). É o que ele diz ser: qualquer um escreve
 * "Googlebot" no navegador. Usado por UserAgents::robot() (RefuseRobots, o
 * `robots.none` dos links de voto do alerta): declarado como bot, 404.
 *
 * Para ampliar, ponha o nome em ROBOTS (vale o primeiro da lista que aparece
 * no User-Agent, sem diferenciar maiúsculas; o mais específico vem antes).
 */
final class RobotCatalog
{
    /** Os tipos de robô. */
    public const KINDS = [
        'ai_user' => 'IA a pedido de alguém',
        'ai_search' => 'índice de busca de IA',
        'search' => 'buscador',
        'ai_training' => 'treino de IA',
        'preview' => 'prévia de link',
        'seo' => 'SEO/monitor',
        'scanner' => 'ferramenta de varredura',
        'ours' => 'nosso',
        'unknown' => 'desconhecido',
    ];

    public const UNKNOWN = 'unknown';

    public const SCANNER = 'scanner';

    /** Nome no User-Agent => [dono, tipo]. */
    public const ROBOTS = [
        // IA a pedido de alguém: a pessoa perguntou, e a IA abriu a página para responder.
        'ChatGPT-User' => ['OpenAI', 'ai_user'],
        'Claude-User' => ['Anthropic', 'ai_user'],
        'Claude-Web' => ['Anthropic', 'ai_user'],
        'Perplexity-User' => ['Perplexity', 'ai_user'],
        'ExaUser' => ['Exa', 'ai_user'],
        'MistralAI-User' => ['Mistral AI', 'ai_user'],
        'meta-externalfetcher' => ['Meta', 'ai_user'],
        'Amzn-User' => ['Amazon', 'ai_user'],
        'NovaAct' => ['Amazon', 'ai_user'],
        'DuckAssistBot' => ['DuckDuckGo', 'ai_user'],
        'GoogleAgent-Mariner' => ['Google', 'ai_user'],
        'Google-NotebookLM' => ['Google', 'ai_user'],
        'Gemini-Deep-Research' => ['Google', 'ai_user'],
        'cohere-ai' => ['Cohere', 'ai_user'],
        'FirecrawlAgent' => ['Firecrawl', 'ai_user'],
        // O leitor em voz alta do Google: busca a página quando a pessoa pede para ouvir (vem de google-proxy-66-249-*).
        'Google-Read-Aloud' => ['Google', 'ai_user'],

        // Índice de busca de IA: guarda a página para citar nas respostas.
        'OAI-SearchBot' => ['OpenAI', 'ai_search'],
        'Claude-SearchBot' => ['Anthropic', 'ai_search'],
        'PerplexityBot' => ['Perplexity', 'ai_search'],
        'ExaSearchBot' => ['Exa', 'ai_search'],
        'Amzn-SearchBot' => ['Amazon', 'ai_search'],
        'Google-CloudVertexBot' => ['Google', 'ai_search'],
        'YouBot' => ['You.com', 'ai_search'],
        'PhindBot' => ['Phind', 'ai_search'],
        'iaskspider' => ['iAsk', 'ai_search'],
        'Andibot' => ['Andi', 'ai_search'],

        // Treino de IA (o Google-Extended e o Applebot-Extended só existem no robots.txt, nunca no navegador).
        'GPTBot' => ['OpenAI', 'ai_training'],
        'ClaudeBot' => ['Anthropic', 'ai_training'],
        'anthropic-ai' => ['Anthropic', 'ai_training'],
        'meta-externalagent' => ['Meta', 'ai_training'],
        'FacebookBot' => ['Meta', 'ai_training'],
        'Bytespider' => ['ByteDance', 'ai_training'],
        'CCBot' => ['Common Crawl', 'ai_training'],
        'Amazonbot' => ['Amazon', 'ai_training'],
        'cohere-training-data-crawler' => ['Cohere', 'ai_training'],
        'Diffbot' => ['Diffbot', 'ai_training'],
        'AI2Bot' => ['Ai2', 'ai_training'],
        'PanguBot' => ['Huawei', 'ai_training'],
        'Timpibot' => ['Timpi', 'ai_training'],
        'ImagesiftBot' => ['ImageSift', 'ai_training'],
        'Webzio-Extended' => ['Webz.io', 'ai_training'],
        'omgili' => ['Webz.io', 'ai_training'],
        'Kangaroo Bot' => ['Kangaroo LLM', 'ai_training'],

        // Prévia de link: alguém colou o endereço numa conversa ou num post. O iMessage se
        // apresenta como Facebook e Twitter juntos; ele vem antes dos dois.
        'Facebot Twitterbot' => ['Apple (iMessage)', 'preview'],
        'Slackbot' => ['Slack', 'preview'],
        'Slack-ImgProxy' => ['Slack', 'preview'],
        'Discordbot' => ['Discord', 'preview'],
        'TelegramBot' => ['Telegram', 'preview'],
        'Twitterbot' => ['X', 'preview'],
        'facebookexternalhit' => ['Meta', 'preview'],
        'Facebot' => ['Meta', 'preview'],
        'LinkedInBot' => ['LinkedIn', 'preview'],
        'WhatsApp' => ['WhatsApp', 'preview'],
        'redditbot' => ['Reddit', 'preview'],
        'ProductHuntPreviewBot' => ['Product Hunt', 'preview'],
        'Mastodon' => ['Mastodon', 'preview'],
        'Bluesky' => ['Bluesky', 'preview'],
        'Pinterestbot' => ['Pinterest', 'preview'],
        'TikTokSpider' => ['ByteDance', 'preview'],
        'SkypeUriPreview' => ['Microsoft', 'preview'],
        'MicrosoftPreview' => ['Microsoft', 'preview'],
        'BingPreview' => ['Microsoft', 'preview'],
        'Google-PageRenderer' => ['Google', 'preview'],
        'Snap URL Preview' => ['Snap', 'preview'],
        'vkShare' => ['VK', 'preview'],
        'Discourse Forum Onebox' => ['Discourse', 'preview'],
        'Embedly' => ['Embedly', 'preview'],
        'Iframely' => ['Iframely', 'preview'],
        // O Gmail busca a imagem do e-mail quando a pessoa abre (via ggpht.com).
        'GoogleImageProxy' => ['Google', 'preview'],

        // Buscador.
        'Googlebot' => ['Google', 'search'],
        'Storebot-Google' => ['Google', 'search'],
        'GoogleOther' => ['Google', 'search'],
        'FeedFetcher-Google' => ['Google', 'search'],
        'bingbot' => ['Microsoft', 'search'],
        'msnbot' => ['Microsoft', 'search'],
        'Applebot' => ['Apple', 'search'],
        'DuckDuckBot' => ['DuckDuckGo', 'search'],
        'DuckDuckGo-Favicons-Bot' => ['DuckDuckGo', 'search'],
        'YandexBot' => ['Yandex', 'search'],
        'YandexImages' => ['Yandex', 'search'],
        'YandexMobileBot' => ['Yandex', 'search'],
        'YandexRenderResourcesBot' => ['Yandex', 'search'],
        'Baiduspider' => ['Baidu', 'search'],
        'SeznamBot' => ['Seznam', 'search'],
        'Yeti/' => ['Naver', 'search'],
        'PetalBot' => ['Huawei', 'search'],
        'Qwantbot' => ['Qwant', 'search'],
        'MojeekBot' => ['Mojeek', 'search'],
        'coccocbot' => ['Cốc Cốc', 'search'],
        'Sogou web spider' => ['Sogou', 'search'],
        'YisouSpider' => ['Alibaba', 'search'],
        'Google Favicon' => ['Google', 'search'],

        // SEO e monitoria: ferramenta de quem cuida de sites (o nosso ou o de outro).
        'Google-InspectionTool' => ['Google', 'seo'],
        'Chrome-Lighthouse' => ['Google', 'seo'],
        'AdsBot-Google' => ['Google', 'seo'],
        'Mediapartners-Google' => ['Google', 'seo'],
        'APIs-Google' => ['Google', 'seo'],
        'Google-Site-Verification' => ['Google', 'seo'],
        'Google-Safety' => ['Google', 'seo'],
        'adidxbot' => ['Microsoft', 'seo'],
        'AhrefsBot' => ['Ahrefs', 'seo'],
        'AhrefsSiteAudit' => ['Ahrefs', 'seo'],
        'SemrushBot' => ['Semrush', 'seo'],
        'SiteAuditBot' => ['Semrush', 'seo'],
        'DotBot' => ['Moz', 'seo'],
        'rogerbot' => ['Moz', 'seo'],
        'MJ12bot' => ['Majestic', 'seo'],
        'DataForSeoBot' => ['DataForSEO', 'seo'],
        'BLEXBot' => ['WebMeUp', 'seo'],
        'serpstatbot' => ['Serpstat', 'seo'],
        'SERankingBacklinksBot' => ['SE Ranking', 'seo'],
        'Barkrowler' => ['Babbar', 'seo'],
        'Screaming Frog' => ['Screaming Frog', 'seo'],
        'Sitebulb' => ['Sitebulb', 'seo'],
        'UptimeRobot' => ['UptimeRobot', 'seo'],
        'Pingdom' => ['Pingdom', 'seo'],
        'StatusCake' => ['StatusCake', 'seo'],
        'Uptime-Kuma' => ['Uptime Kuma', 'seo'],
        'Site24x7' => ['Site24x7', 'seo'],
        'Better Uptime' => ['Better Stack', 'seo'],
        'GTmetrix' => ['GTmetrix', 'seo'],
        'Cloudflare-' => ['Cloudflare', 'seo'],

        // Varredura da internet inteira, com dono: procura serviço exposto, não lê o site.
        'CensysInspect' => ['Censys', 'scanner'],
        'Expanse, a Palo Alto Networks company' => ['Palo Alto Networks', 'scanner'],

        // Coletores genéricos: robô com nome, mas sem um uso só (qualquer um roda o Nutch e o Heritrix).
        'Nutch' => ['Apache Nutch (coletor genérico)', 'unknown'],
        'heritrix' => ['Heritrix (coletor genérico)', 'unknown'],
        'archive.org_bot' => ['Internet Archive', 'unknown'],
    ];

    /** Ferramentas de varredura e de ataque: robô sem dono (qualquer um roda), que procura falha. */
    public const SCANNERS = [
        'zgrab', 'Nuclei', 'Nmap Scripting Engine', 'masscan', 'Nikto', 'sqlmap', 'WPScan', 'gobuster',
        'Fuzz Faster U Fool', 'feroxbuster', 'DirBuster', 'wfuzz', 'Nessus', 'OpenVAS', 'Acunetix', 'Netsparker',
        'Invicti', 'WhatWeb', 'l9explore', 'l9tcpid', 'ModatScanner', 'InternetMeasurement',
    ];

    /** Ferramentas e bibliotecas: robô, mas sem dono (qualquer um roda). */
    public const TOOLS = [
        'curl', 'Wget', 'python-requests', 'python-httpx', 'Python-urllib', 'aiohttp', 'Go-http-client', 'okhttp', 'axios',
        'node-fetch', 'undici', 'Java/', 'Apache-HttpClient', 'libwww-perl', 'Guzzle', 'Scrapy', 'HeadlessChrome',
        'PhantomJS', 'HTTPie', 'PostmanRuntime', 'fasthttp', 'PycURL',
    ];

    /**
     * O robô que o User-Agent declara; nulo quando não é nenhum conhecido (aí
     * vale o teste genérico de UserAgents::isBot).
     *
     * @return array{owner: ?string, kind: string, name: string}|null
     */
    public static function find(string $userAgent): ?array
    {
        foreach (self::ROBOTS as $name => [$owner, $kind]) {
            if (stripos($userAgent, $name) !== false) {
                return ['owner' => $owner, 'kind' => $kind, 'name' => $name];
            }
        }

        foreach (self::SCANNERS as $name) {
            if (stripos($userAgent, $name) !== false) {
                return ['owner' => null, 'kind' => self::SCANNER, 'name' => $name];
            }
        }

        foreach (self::TOOLS as $name) {
            if (stripos($userAgent, $name) !== false) {
                return ['owner' => null, 'kind' => self::UNKNOWN, 'name' => $name];
            }
        }

        return null;
    }

    public static function label(?string $kind): ?string
    {
        return $kind === null ? null : (self::KINDS[$kind] ?? self::KINDS[self::UNKNOWN]);
    }
}
