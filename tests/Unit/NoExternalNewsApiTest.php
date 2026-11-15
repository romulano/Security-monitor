<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A notícia tem que vir dos feeds das fontes, nunca de uma API de terceiros.
 * Todo acesso à rede fica nos arquivos abaixo, cada um com o seu motivo: um
 * arquivo novo que fale com a internet faz este teste falhar até entrar na
 * lista, de forma consciente.
 */
class NoExternalNewsApiTest extends TestCase
{
    /** Arquivo => por que ele pode sair para a rede. */
    private const ALLOWED = [
        'app/Services/Crawler/CrawlerHttp.php' => 'a requisição da coleta das fontes: o RSS/Atom ou o índice JSON do próprio site (FeedReader) e a página da matéria (ArticleExtractor), direto ou pelo proxy da fonte',
        'app/Services/Security/TlsProbe.php' => 'monitoria de segurança: handshake TLS no site do admin, só para ler o certificado e o protocolo (IP público e já validado)',
        'app/Services/Enrichment/ClaudeEnricher.php' => 'driver de IA',
        'app/Services/Enrichment/OpenAiEnricher.php' => 'driver de IA',
        'app/Services/Enrichment/OpenRouterEnricher.php' => 'driver de IA (o /models do OpenRouter; a análise vai pelo OpenAiEnricher)',
        'app/Services/Alerts/Discovery/SafeFetcher.php' => 'descoberta no site do cliente',
        'app/Services/Keys/ArchiveUrls.php' => 'varredura de chaves: o índice do Web Archive (só o nome do domínio comprovado sai)',
        'app/Services/Alerts/Discovery/NativeDnsResolver.php' => 'TXT de verificação do domínio',
        'app/Services/Alerts/Slack/SlackClient.php' => 'entrega dos alertas e OAuth do Slack',
        'app/Services/Alerts/Discord/DiscordClient.php' => 'OAuth, canais e entrega no Discord',
        'app/Services/Alerts/Discord/DiscordGateway.php' => 'conexão única do bot ao gateway do Discord',
        'app/Services/Alerts/Telegram/TelegramClient.php' => 'entrega no Telegram e mensagens do bot (long polling)',
        'app/Services/Alerts/Webhook/WebhookClient.php' => 'entrega de alerta ao endereço que a própria conta cadastrou e comprovou (webhook: só https:443, IP público já conferido)',
        'app/Support/Turnstile.php' => 'captcha da Cloudflare',
        'app/Services/Auth/GithubOAuth.php' => 'Entrar com o GitHub: OAuth de quem faz login ou cadastro (id, nome e e-mail verificado) e de quem confirma que é você (só o id)',
        'app/Services/Exploits/ExploitDbClient.php' => 'índice e arquivos do Exploit-DB: referência de exploit público, não notícia',
        'app/Services/IndexNow/IndexNowClient.php' => 'aviso de URL nova aos buscadores (IndexNow), não é fonte de notícia',
        'app/Services/Lookalike/CertificateSearch.php' => 'Radar de domínios: certificados públicos com a marca (crt.sh)',
        'app/Services/Lookalike/RdapClient.php' => 'Radar de domínios: data de registro, registrador e contato de abuso (RDAP)',
        'app/Services/Github/GithubClient.php' => 'Monitoramento do GitHub: busca em código e repositórios públicos pela API oficial',
        'app/Services/Hermes/Hermes.php' => 'o cliente único da ponte de IA (hermes): toda etapa com IA passa por ele',
        'app/Services/Admin/EmailReport.php' => 'Admin → E-mails: a saudação do servidor de envio do próprio stack (mail:25, rede interna), para dizer se ele está no ar',
    ];

    /** `\bHttp::` é só a facade: `CrawlerHttp::redact()` limpa texto e não sai para a rede. */
    private const NETWORK = '/\bHttp::|GuzzleHttp|curl_init|fsockopen|stream_socket_client|file_get_contents\(\s*[\'"]https?:|Anthropic\\\\Client|dns_get_record/';

    private const SCANNED = ['app', 'config', 'routes', 'database', 'resources/views'];

    public function test_so_os_arquivos_conhecidos_acessam_a_rede(): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];

        foreach (self::SCANNED as $dir) {
            $path = $root.'/'.$dir;

            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace($root.'/', '', $file->getPathname());
                $contents = (string) file_get_contents($file->getPathname());

                if (! isset(self::ALLOWED[$relative]) && preg_match(self::NETWORK, $contents, $match)) {
                    $hits[] = $relative.' → '.$match[0];
                }
            }
        }

        $this->assertSame(
            [],
            $hits,
            "Acesso à rede fora dos arquivos conhecidos (as notícias devem vir só dos feeds das fontes):\n".implode("\n", $hits)
        );
    }

    /** A lista não acumula entrada velha: arquivo que parou de usar a rede sai dela. */
    public function test_todo_arquivo_da_lista_ainda_acessa_a_rede(): void
    {
        $root = dirname(__DIR__, 2);
        $stale = [];

        foreach (array_keys(self::ALLOWED) as $relative) {
            $file = $root.'/'.$relative;

            if (! is_file($file) || ! preg_match(self::NETWORK, (string) file_get_contents($file))) {
                $stale[] = $relative;
            }
        }

        $this->assertSame([], $stale, "Arquivos da lista que não acessam mais a rede (tire-os daqui):\n".implode("\n", $stale));
    }
}
