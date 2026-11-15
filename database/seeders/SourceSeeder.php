<?php

namespace Database\Seeders;

use App\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Fontes padrão do crawler: cada `feed_url` é o RSS/Atom publicado pela
 * própria fonte (ou, sem RSS, o índice JSON dos posts do próprio site).
 */
class SourceSeeder extends Seeder
{
    public const SOURCES = [
        ['Daily CyberSecurity (securityonline)', 'securityonline.info', 'https://securityonline.info/feed/', 9],
        ['The Hacker News', 'thehackernews.com', 'https://feeds.feedburner.com/TheHackersNews', 9],
        ['BleepingComputer', 'bleepingcomputer.com', 'https://www.bleepingcomputer.com/feed/', 9],
        ['SecurityWeek', 'securityweek.com', 'https://www.securityweek.com/rss.xml', 8],
        ['GBHackers', 'gbhackers.com', 'https://gbhackers.com/feed/', 7],
        ['Security Affairs', 'securityaffairs.com', 'https://securityaffairs.com/wordpress/feed', 8],
        ['The Register — Security', 'theregister.com', 'https://www.theregister.com/security/headlines.atom', 7],
        ['Dark Reading', 'darkreading.com', 'https://www.darkreading.com/rss.xml', 7],
        ['CyberScoop', 'cyberscoop.com', 'https://www.cyberscoop.com/feed/', 6],
        ['Dark Web Informer', 'darkwebinformer.com', 'https://darkwebinformer.com/rss/', 6],
        ['ZDNET — Security', 'zdnet.com', 'https://www.zdnet.com/topic/security/rss.xml', 5],
        // O feed geral, de propósito: o dono quer o TechCrunch inteiro no Security Monitor
        // (2026-11-01, desfazendo a troca pelo da categoria de segurança).
        ['TechCrunch', 'techcrunch.com', 'https://techcrunch.com/feed/', 4],
        ['Digit.fyi', 'digit.fyi', 'https://www.digit.fyi/feed/', 4],
        ['IT Security Guru', 'itsecurityguru.org', 'https://www.itsecurityguru.org/feed/', 5],
        ['Krebs on Security', 'krebsonsecurity.com', 'https://krebsonsecurity.com/feed/', 8],
        ['Schneier on Security', 'schneier.com', 'https://www.schneier.com/feed/atom/', 6],
        ['Troy Hunt', 'troyhunt.com', 'https://www.troyhunt.com/rss/', 5],
        ['Have I Been Pwned — Breaches', 'haveibeenpwned.com', 'https://haveibeenpwned.com/feed/breaches/', 7],
        ['Graham Cluley', 'grahamcluley.com', 'https://grahamcluley.com/feed/', 5],
        ['Unit 42 (Palo Alto)', 'unit42.paloaltonetworks.com', 'https://unit42.paloaltonetworks.com/feed/', 9],
        ['Securelist (Kaspersky)', 'securelist.com', 'https://securelist.com/feed/', 8],
        // O site foi para Next.js e o /feed/ raiz (do WordPress) passou a dar
        // 404. Os posts de /blog/ têm feed próprio.
        ['SentinelOne', 'sentinelone.com', 'https://www.sentinelone.com/blog/feed/', 7],
        ['Check Point Blog', 'blog.checkpoint.com', 'https://blog.checkpoint.com/feed/', 7],
        ['TrustedSec', 'trustedsec.com', 'https://trustedsec.com/feed.rss', 6],
        ['WeLiveSecurity (ESET)', 'welivesecurity.com', 'https://www.welivesecurity.com/feed/', 7],
        ['Tenable Blog', 'tenable.com', 'https://www.tenable.com/blog/feed', 6],
        ['Cloudflare Blog', 'blog.cloudflare.com', 'https://blog.cloudflare.com/rss/', 5],
        ['Cisco Security Blog', 'blogs.cisco.com', 'https://blogs.cisco.com/security/feed', 6],
        ['Cisco PSIRT Advisories', 'sec.cloudapps.cisco.com', 'https://sec.cloudapps.cisco.com/security/center/psirtrss20/CiscoSecurityAdvisory.xml', 8],
        ['Fortinet Threat Research', 'feeds.fortinet.com', 'https://feeds.fortinet.com/fortinet/blog/threat-research', 7],
        ['Proofpoint', 'proofpoint.com', 'https://www.proofpoint.com/us/rss.xml', 6],
        ['F5 Labs', 'f5.com', 'https://www.f5.com/labs/rss-feeds/all.xml', 6],
        ['Trend Micro Newsroom', 'newsroom.trendmicro.com', 'https://newsroom.trendmicro.com/news-releases?pagetemplate=rss&category=787', 5],
        ['SafeDep', 'safedep.io', 'https://safedep.io/rss.xml', 4],
        // O site institucional deixou o WordPress (/feed/ dá 404) e o blog foi
        // para o Ghost do Yokai. Cada post sai em pt-br e em en; o feed da tag
        // `en` evita coletar o mesmo post duas vezes, já que a análise gera as
        // duas edições.
        ['Hakai Security', 'yokai.hakaisecurity.io', 'https://yokai.hakaisecurity.io/tag/en/rss/', 4],
        ['Hacktron AI', 'hacktron.ai', 'https://www.hacktron.ai/rss.xml', 4],
        // A CISA saiu em 2026-11-04: a Akamai dela responde 403 a todo cliente
        // que não é navegador, de qualquer IP (o do servidor, o residencial, por
        // proxy), e não vamos disfarçar o robô. O que ela publicava chega por
        // aqui: os advisories conjuntos (AA) também saem na NCSC do Reino Unido,
        // que assina boa parte deles; as falhas críticas, na CERT-EU; e as
        // inclusões no KEV, na imprensa que já está no catálogo.
        ['NCSC (Reino Unido)', 'ncsc.gov.uk', 'https://www.ncsc.gov.uk/api/1/services/v1/news-rss-feed.xml', 8],
        // Só falhas críticas (~4/mês), com produto, versões e CVE na página.
        ['CERT-EU — Security Advisories', 'cert.europa.eu', 'https://cert.europa.eu/publications/security-advisories-rss', 8],
        // Poucos posts (~4/mês), mas com dados próprios de varredura e exploração.
        ['GreyNoise Blog', 'greynoise.io', 'https://www.greynoise.io/blog/rss.xml', 6],

        // Leva de 2026-10-20, todas testadas com o FeedReader e o
        // ArticleExtractor. Laboratórios de pesquisa:
        ['CrowdStrike Blog', 'crowdstrike.com', 'https://www.crowdstrike.com/en-us/blog/feed', 8],
        ['Huntress Blog', 'huntress.com', 'https://www.huntress.com/blog/rss.xml', 7],
        ['Elastic Security Labs', 'elastic.co', 'https://www.elastic.co/security-labs/rss/feed.xml', 7],
        ['Recorded Future (Insikt Group)', 'recordedfuture.com', 'https://www.recordedfuture.com/feed', 7],
        ['Volexity', 'volexity.com', 'https://www.volexity.com/feed/', 8],
        // O braço de pesquisa; o blog.checkpoint.com acima é o corporativo.
        ['Check Point Research', 'research.checkpoint.com', 'https://research.checkpoint.com/feed/', 8],
        ['Sekoia TDR', 'sekoia.com', 'https://www.sekoia.com/blog/rss.xml', 7],
        ['Wiz Research', 'wiz.io', 'https://www.wiz.io/feed/rss.xml', 7],
        ['Aikido Security', 'aikido.dev', 'https://www.aikido.dev/blog/rss.xml', 6],
        ['Citizen Lab', 'citizenlab.ca', 'https://citizenlab.ca/feed/', 7],
        // O blog foi para o Adobe Edge Delivery e o /blog/feed/ passou a dar 404
        // (2026-11). Os posts vêm do índice JSON do próprio blog, que traz o
        // blog inteiro: o que vem depois do # deixa só os do Threat Intel.
        ['Infoblox Threat Intel', 'infoblox.com', 'https://www.infoblox.com/blog-index.json#/blog/threat-intelligence/', 6],
        ['Flashpoint', 'flashpoint.io', 'https://flashpoint.io/feed/', 6],
        ['Infostealers (Hudson Rock)', 'infostealers.com', 'https://www.infostealers.com/feed/', 6],
        ['watchTowr Labs', 'labs.watchtowr.com', 'https://labs.watchtowr.com/rss/', 8],
        ['The DFIR Report', 'thedfirreport.com', 'https://thedfirreport.com/feed/', 7],
        ['Zero Day Initiative — Blog', 'zerodayinitiative.com', 'https://www.zerodayinitiative.com/blog?format=rss', 7],
        // Imprensa: a geral já está coberta; entram as de nicho (saúde, OT, Brasil).
        ['Infosecurity Magazine', 'infosecurity-magazine.com', 'https://www.infosecurity-magazine.com/rss/news/', 6],
        ['WIRED — Security', 'wired.com', 'https://www.wired.com/feed/category/security/latest/rss', 5],
        ['HIPAA Journal', 'hipaajournal.com', 'https://www.hipaajournal.com/feed/', 5],
        ['Industrial Cyber', 'industrialcyber.co', 'https://industrialcyber.co/feed/', 5],
        ['Olhar Digital — Segurança', 'olhardigital.com.br', 'https://olhardigital.com.br/editorias/seguranca/feed/', 4],
        // Alertas oficiais e boletins de correção. O feed de alertas do CERT-FR
        // (/alerte/feed/) fica de fora: vem do mais antigo para o mais novo.
        ['CERT-FR (ANSSI)', 'cert.cyber.gouv.fr', 'https://cert.cyber.gouv.fr/feed/', 8],
        ['FBI IC3 — PSAs', 'ic3.gov', 'https://www.ic3.gov/PSA/RSS', 8],
        ['Palo Alto Networks PSIRT', 'security.paloaltonetworks.com', 'https://security.paloaltonetworks.com/rss.xml', 8],
        // A página do advisory rende pouco texto, mas o CVE e o produto chegam.
        ['Fortinet PSIRT', 'fortiguard.fortinet.com', 'https://filestore.fortinet.com/fortiguard/rss/ir.xml', 8],
        ['GitLab Patch Releases', 'docs.gitlab.com', 'https://docs.gitlab.com/releases/patch-releases.xml', 7],
        ['Patchstack', 'patchstack.com', 'https://patchstack.com/feed/', 5],
        // Pesquisadores independentes:
        ['Zero Day (Kim Zetter)', 'zetter-zeroday.com', 'https://www.zetter-zeroday.com/rss/', 6],
        ['BushidoToken (Will Thomas)', 'blog.bushidotoken.net', 'https://blog.bushidotoken.net/feeds/posts/default', 5],
        ['Objective-See (Patrick Wardle)', 'objective-see.org', 'https://objective-see.org/rss.xml', 5],

        // Leva de 2026-11-01, testadas com o FeedReader e o ArticleExtractor.
        // O Help Net também publica comunicado de produto e um "Week in review"
        // semanal com vários assuntos.
        ['Help Net Security', 'helpnetsecurity.com', 'https://www.helpnetsecurity.com/feed/', 6],
        ['HackRead', 'hackread.com', 'https://hackread.com/feed/', 5],
        ['Malwarebytes Labs', 'malwarebytes.com', 'https://www.malwarebytes.com/blog/feed/index.xml', 6],
        ['Cybersecurity Dive', 'cybersecuritydive.com', 'https://www.cybersecuritydive.com/feeds/news/', 5],
        // Poucos posts, mas pesquisa própria a fundo (nuvem, cadeia de suprimentos).
        ['Datadog Security Labs', 'securitylabs.datadoghq.com', 'https://securitylabs.datadoghq.com/rss/feed.xml', 7],
    ];

    /**
     * Só cria as fontes que faltam: o que já existe (inclusive desligado ou
     * reconfigurado no admin) fica como está. Por isso as migrations podem
     * chamar o seeder para trazer fontes novas até a produção.
     */
    public function run(): void
    {
        foreach (self::SOURCES as [$name, $host, $feed, $weight]) {
            Source::query()->firstOrCreate(
                ['feed_url' => $feed],
                [
                    'name' => $name,
                    'host' => $host,
                    'site_url' => 'https://'.$host,
                    'enabled' => true,
                    'interval_minutes' => 60,
                    'weight' => $weight,
                ]
            );
        }
    }
}
