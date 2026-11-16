<?php

namespace Tests\Unit;

use App\Support\RobotCatalog;
use App\Support\UserAgents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** De quem é o robô e para que ele lê o site, pelo nome que ele declara no navegador (2026-11-01). */
class RobotCatalogTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string, 2: string}> navegador, dono, tipo */
    public static function robots(): array
    {
        return [
            'ChatGPT a pedido' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot', 'OpenAI', 'ai_user'],
            'Claude a pedido' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 'Anthropic', 'ai_user'],
            'Perplexity a pedido' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)', 'Perplexity', 'ai_user'],
            'Exa a pedido' => ['Mozilla/5.0 (compatible; ExaUser/1.0; +https://exa.ai)', 'Exa', 'ai_user'],
            'busca do ChatGPT' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot', 'OpenAI', 'ai_search'],
            'busca do Claude' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-SearchBot/1.0; +https://www.anthropic.com)', 'Anthropic', 'ai_search'],
            'índice do Perplexity' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 'Perplexity', 'ai_search'],
            'Googlebot no celular' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.7390.122 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Google', 'search'],
            'Bing' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36', 'Microsoft', 'search'],
            'Apple' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)', 'Apple', 'search'],
            'DuckDuckGo' => ['DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)', 'DuckDuckGo', 'search'],
            'treino da OpenAI' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', 'OpenAI', 'ai_training'],
            'treino da Anthropic' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'Anthropic', 'ai_training'],
            'ByteDance' => ['Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)', 'ByteDance', 'ai_training'],
            'Meta' => ['meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)', 'Meta', 'ai_training'],
            'Common Crawl' => ['CCBot/2.0 (https://commoncrawl.org/faq/)', 'Common Crawl', 'ai_training'],
            'Slack' => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', 'Slack', 'preview'],
            'Discord' => ['Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)', 'Discord', 'preview'],
            'Telegram (que diz ser como o do Twitter)' => ['TelegramBot (like TwitterBot)', 'Telegram', 'preview'],
            'X' => ['Twitterbot/1.0', 'X', 'preview'],
            'Facebook' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'Meta', 'preview'],
            'LinkedIn (com a biblioteca HTTP no nome)' => ['LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)', 'LinkedIn', 'preview'],
            'WhatsApp' => ['WhatsApp/2.23.20.0 A', 'WhatsApp', 'preview'],
            'Product Hunt' => ['Mozilla/5.0 (compatible; ProductHuntPreviewBot/1.0; +https://www.producthunt.com)', 'Product Hunt', 'preview'],
            'iMessage' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_1) AppleWebKit/601.2.4 (KHTML, like Gecko) Version/9.0.1 Safari/601.2.4 facebookexternalhit/1.1 Facebot Twitterbot/1.0', 'Apple (iMessage)', 'preview'],
            'Ahrefs' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', 'Ahrefs', 'seo'],
            'Semrush' => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)', 'Semrush', 'seo'],
            'monitor' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)', 'UptimeRobot', 'seo'],
            // 2026-11-01: passavam como gente.
            'leitor em voz alta do Google' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36 (compatible; Google-Read-Aloud; +https://support.google.com/webmasters/answer/1061943)', 'Google', 'ai_user'],
            'Apache Nutch' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36/Nutch-1.23-SNAPSHOT', 'Apache Nutch (coletor genérico)', 'unknown'],
            'Heritrix' => ['Mozilla/5.0 (compatible; heritrix/3.4.0 +https://example.org/crawler)', 'Heritrix (coletor genérico)', 'unknown'],
            'imagem do Gmail' => ['Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)', 'Google', 'preview'],
            'favicon do Google' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.75 Safari/537.36 Google Favicon', 'Google', 'search'],
            'Censys' => ['Mozilla/5.0 (compatible; CensysInspect/1.1; +https://about.censys.io/)', 'Censys', 'scanner'],
            'Expanse' => ['Expanse, a Palo Alto Networks company, searches across the global IPv4 space multiple times per day', 'Palo Alto Networks', 'scanner'],
            // 2026-12-05: as ferramentas de varredura passavam como gente (Nikto, sqlmap) ou como desconhecido.
            'zgrab, sem dono' => ['Mozilla/5.0 zgrab/0.x', null, 'scanner'],
            'Nikto' => ['Mozilla/5.00 (Nikto/2.5.0) (Evasions:None) (Test:000001)', null, 'scanner'],
            'sqlmap' => ['sqlmap/1.8.4#stable (https://sqlmap.org)', null, 'scanner'],
            'Nuclei' => ['Mozilla/5.0 (compatible; Nuclei - Open-source project (github.com/projectdiscovery/nuclei))', null, 'scanner'],
            'ffuf' => ['Fuzz Faster U Fool v2.1.0-dev', null, 'scanner'],
            'curl, sem dono' => ['curl/8.5.0', null, 'unknown'],
            'robô fora do catálogo' => ['Mozilla/5.0 (compatible; SomeNewCrawler/0.1; +https://example.net)', null, 'unknown'],
            'sem navegador' => ['', null, 'unknown'],
            // Qualquer um escreve SecurityMonitorBot: o nome sozinho não diz nada.
            'SecurityMonitorBot de fora' => ['SecurityMonitorBot/1.0 (+https://example.com)', null, 'unknown'],
        ];
    }

    #[DataProvider('robots')]
    public function test_the_robot_is_known_by_the_name_it_gives(string $userAgent, ?string $owner, string $kind): void
    {
        $robot = UserAgents::robot($userAgent);

        $this->assertNotNull($robot);
        $this->assertSame([$owner, $kind], [$robot['owner'], $robot['kind']]);
        $this->assertTrue(UserAgents::isBot($userAgent));
        $this->assertArrayHasKey($kind, RobotCatalog::KINDS);
    }

    public function test_people_are_not_robots(): void
    {
        foreach ([
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
            // O navegador de dentro do app (Instagram, Facebook) é gente lendo.
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 357.0.0.25.101',
            'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36 [FBAN/EMA;FBLC/pt_BR;FBAV/400.0.0.10.110;]',
            'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:144.0) Gecko/20100101 Firefox/144.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 OPR/120.0.0.0',
            'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/28.0 Chrome/130.0.0.0 Mobile Safari/537.36',
        ] as $person) {
            $this->assertNull(UserAgents::robot($person), $person);
            $this->assertFalse(UserAgents::isBot($person));
        }
    }

    /** Ampliar o catálogo não pode deixar um nome sem efeito: o de cima que cabe dentro dele ganharia sempre. */
    public function test_no_name_in_the_catalog_is_hidden_by_an_earlier_one(): void
    {
        $names = array_keys(RobotCatalog::ROBOTS);

        foreach ($names as $i => $later) {
            foreach (array_slice($names, 0, $i) as $earlier) {
                if (stripos($later, $earlier) !== false) {
                    $this->assertSame(RobotCatalog::ROBOTS[$earlier], RobotCatalog::ROBOTS[$later], "{$earlier} vem antes e esconde {$later}");
                }
            }

            $this->assertArrayHasKey(RobotCatalog::ROBOTS[$later][1], RobotCatalog::KINDS, $later);
            $this->assertNotSame('ours', RobotCatalog::ROBOTS[$later][1], 'o "nosso" é pelo IP, nunca pelo nome');
        }

        // A ferramenta de varredura vem depois do catálogo: nenhum nome de cima pode escondê-la.
        foreach (RobotCatalog::SCANNERS as $tool) {
            $this->assertSame($tool, RobotCatalog::find($tool)['name'] ?? null, $tool);
        }
    }
}
