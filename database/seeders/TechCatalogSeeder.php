<?php

namespace Database\Seeders;

use App\Models\TechCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo inicial de tecnologias.
 *
 * `terms` são procurados como palavra inteira (sem caixa) nos produtos afetados,
 * fornecedores, título e resumo dos casos; termo com * no fim casa por prefixo.
 * Evitamos termos que são palavras comuns em inglês ("react", "rails", "zoom"),
 * que gerariam alerta para qualquer texto.
 *
 * Só cria o que ainda não existe: o que o admin editou no catálogo vence o seed.
 */
class TechCatalogSeeder extends Seeder
{
    /** Amplas demais para contar por menção no texto (veja a migration de `strict`). */
    public const STRICT = [
        'php', 'javascript', 'python', 'java', 'dotnet', 'go', 'ruby', 'kotlin', 'swift', 'rust', 'c-cpp',
        'linux', 'windows', 'macos', 'android', 'ios',
        'github', 'gitlab', 'bitbucket',
        'aws', 'azure', 'gcp', 'docker',
        // "lovable" é adjetivo comum em inglês: só conta como produto afetado.
        'lovable',
    ];

    public function run(): void
    {
        $position = [];

        foreach ($this->items() as [$slug, $name, $category, $parent, $terms, $detect]) {
            $position[$category] = ($position[$category] ?? 0) + 1;

            if (TechCatalog::query()->where('slug', $slug)->exists()) {
                continue;
            }

            TechCatalog::query()->create([
                'slug' => $slug,
                'name' => $name,
                'category' => $category,
                'parent_id' => $parent ? TechCatalog::query()->where('slug', $parent)->value('id') : null,
                'terms' => $terms,
                'detect' => $detect,
                'active' => true,
                'position' => $position[$category],
            ]);

            // A coluna `strict` chega numa migration posterior; em banco novo o
            // seeder roda antes dela, e a própria migration marca os amplos.
            if (Schema::hasColumn('tech_catalog', 'strict')) {
                TechCatalog::query()->where('slug', $slug)->update(['strict' => in_array($slug, self::STRICT, true)]);
            }
        }
    }

    /** @return list<array{0:string,1:string,2:string,3:?string,4:list<string>,5:list<array>}> */
    private function items(): array
    {
        $h = fn (string $name, string $pattern) => ['type' => 'header', 'name' => $name, 'pattern' => $pattern];
        $cookie = fn (string $pattern) => ['type' => 'cookie', 'pattern' => $pattern];
        $html = fn (string $pattern) => ['type' => 'html', 'pattern' => $pattern];
        $mx = fn (string $pattern) => ['type' => 'mx', 'pattern' => $pattern];
        $spf = fn (string $pattern) => ['type' => 'spf', 'pattern' => $pattern];
        $ns = fn (string $pattern) => ['type' => 'ns', 'pattern' => $pattern];
        $txt = fn (string $pattern) => ['type' => 'txt', 'pattern' => $pattern];
        $cname = fn (string $pattern) => ['type' => 'cname', 'pattern' => $pattern];
        $gen = fn (string $pattern) => ['type' => 'generator', 'pattern' => $pattern];
        $script = fn (string $pattern) => ['type' => 'script', 'pattern' => $pattern];

        return [
            // Linguagens: o runtime e o ecossistema de pacotes.
            ['php', 'PHP', 'language', null, ['php', 'composer', 'packagist'], [$h('x-powered-by', 're:PHP/?([\d.]+)?'), $cookie('PHPSESSID')]],
            ['javascript', 'JavaScript / TypeScript', 'language', null, ['node.js', 'nodejs', 'npm', 'typescript'], []],
            ['python', 'Python', 'language', null, ['python', 'pypi', 'cpython'], []],
            ['java', 'Java', 'language', null, ['java', 'openjdk', 'jdk', 'maven central'], [$cookie('JSESSIONID')]],
            ['dotnet', 'C# / .NET', 'language', null, ['asp.net', '.net framework', '.net core', 'nuget'], [$h('x-aspnet-version', 're:([\d.]+)'), $h('x-powered-by', 'ASP.NET'), $cookie('ASP.NET_SessionId')]],
            ['go', 'Go', 'language', null, ['golang', 'go modules'], []],
            ['ruby', 'Ruby', 'language', null, ['ruby', 'rubygems'], []],
            ['kotlin', 'Kotlin', 'language', null, ['kotlin'], []],
            ['swift', 'Swift', 'language', null, ['swiftui', 'swift package manager'], []],
            ['rust', 'Rust', 'language', null, ['rust', 'crates.io'], []],
            ['c-cpp', 'C / C++', 'language', null, ['glibc', 'libc'], []],

            // Frameworks, filhos da linguagem (a entrevista filtra por ela).
            ['laravel', 'Laravel', 'framework', 'php', ['laravel', 'livewire'], [$cookie('laravel_session')]],
            ['symfony', 'Symfony', 'framework', 'php', ['symfony'], [$h('x-debug-token', 're:.+')]],
            ['wordpress', 'WordPress', 'cms', 'php', ['wordpress'], [$html('/wp-content/'), $html('re:<meta[^>]+generator[^>]+WordPress ?([\d.]+)?'), $gen('WordPress')]],
            ['drupal', 'Drupal', 'framework', 'php', ['drupal'], [$h('x-generator', 'Drupal'), $html('Drupal.settings'), $gen('Drupal')]],
            ['codeigniter', 'CodeIgniter', 'framework', 'php', ['codeigniter'], [$cookie('ci_session')]],
            ['react', 'React', 'framework', 'javascript', ['react.js', 'reactjs', 'react server components', 'react-dom'], [$html('data-reactroot')]],
            ['nextjs', 'Next.js', 'framework', 'javascript', ['next.js', 'nextjs'], [$html('__NEXT_DATA__'), $html('/_next/static'), $h('x-powered-by', 'Next.js')]],
            ['vue', 'Vue', 'framework', 'javascript', ['vue.js', 'vuejs'], []],
            ['nuxt', 'Nuxt', 'framework', 'javascript', ['nuxt', 'nuxt.js'], [$html('__NUXT__')]],
            ['angular', 'Angular', 'framework', 'javascript', ['angular', 'angularjs'], [$html('re:ng-version="([\d.]+)"')]],
            ['express', 'Node.js / Express', 'framework', 'javascript', ['express.js', 'expressjs'], [$h('x-powered-by', 'Express')]],
            ['nestjs', 'NestJS', 'framework', 'javascript', ['nestjs'], []],
            ['django', 'Django', 'framework', 'python', ['django'], [$html('csrfmiddlewaretoken')]],
            ['flask', 'Flask', 'framework', 'python', ['flask', 'werkzeug'], [$h('server', 'Werkzeug')]],
            ['fastapi', 'FastAPI', 'framework', 'python', ['fastapi', 'uvicorn'], [$h('server', 'uvicorn')]],
            ['spring', 'Spring', 'framework', 'java', ['spring framework', 'spring boot', 'spring4shell', 'spring cloud'], []],
            ['struts', 'Struts', 'framework', 'java', ['apache struts', 'struts'], []],
            ['quarkus', 'Quarkus', 'framework', 'java', ['quarkus'], []],
            ['aspnet-core', 'ASP.NET Core', 'framework', 'dotnet', ['asp.net core'], []],
            ['blazor', 'Blazor', 'framework', 'dotnet', ['blazor'], []],
            ['gin', 'Gin', 'framework', 'go', ['gin-gonic'], []],
            ['fiber', 'Fiber', 'framework', 'go', ['gofiber'], []],
            ['rails', 'Rails', 'framework', 'ruby', ['ruby on rails', 'actionpack', 'activerecord', 'rails framework'], []],
            ['sinatra', 'Sinatra', 'framework', 'ruby', ['sinatra'], []],
            ['ktor', 'Ktor', 'framework', 'kotlin', ['ktor'], []],
            ['vapor', 'Vapor', 'framework', 'swift', ['vapor framework'], []],
            ['actix', 'Actix', 'framework', 'rust', ['actix'], []],
            ['axum', 'Axum', 'framework', 'rust', ['axum'], []],

            ['github', 'GitHub', 'devops', null, ['github'], []],
            ['gitlab', 'GitLab', 'devops', null, ['gitlab'], []],
            ['bitbucket', 'Bitbucket', 'devops', null, ['bitbucket'], []],
            ['azure-devops', 'Azure DevOps', 'devops', null, ['azure devops'], []],
            ['github-actions', 'GitHub Actions', 'devops', null, ['github actions'], []],
            ['gitlab-ci', 'GitLab CI', 'devops', null, ['gitlab ci', 'gitlab runner'], []],
            ['jenkins', 'Jenkins', 'devops', null, ['jenkins'], []],
            ['circleci', 'CircleCI', 'devops', null, ['circleci'], []],

            ['linux', 'Linux', 'os', null, ['linux kernel', 'linux'], []],
            ['ubuntu', 'Ubuntu', 'os', 'linux', ['ubuntu'], [$h('server', 'Ubuntu')]],
            ['debian', 'Debian', 'os', 'linux', ['debian'], [$h('server', 'Debian')]],
            ['rhel', 'RHEL / Rocky / Alma', 'os', 'linux', ['red hat enterprise linux', 'rhel', 'rocky linux', 'almalinux', 'centos'], [$h('server', 'CentOS'), $h('server', 'Red Hat')]],
            ['amazon-linux', 'Amazon Linux', 'os', 'linux', ['amazon linux'], []],
            ['suse', 'SUSE', 'os', 'linux', ['suse', 'sles'], []],
            ['windows-server', 'Windows Server', 'os', null, ['windows server'], []],

            ['aws', 'AWS', 'cloud', null, ['aws', 'amazon web services', 'amazon s3', 'amazon ec2'], [$h('server', 'AmazonS3'), $h('x-amz-cf-id', 're:.+'), $ns('awsdns')]],
            ['azure', 'Microsoft Azure', 'cloud', null, ['microsoft azure', 'azure'], [$ns('azure-dns')]],
            ['gcp', 'Google Cloud', 'cloud', null, ['google cloud', 'gcp'], [$h('via', 'google')]],
            ['oracle-cloud', 'Oracle Cloud', 'cloud', null, ['oracle cloud'], []],
            ['digitalocean', 'DigitalOcean', 'cloud', null, ['digitalocean'], [$ns('digitalocean.com')]],
            ['vercel', 'Vercel', 'cloud', null, ['vercel'], [$h('x-vercel-id', 're:.+'), $h('server', 'Vercel'), $cname('vercel-dns.com')]],

            ['nginx', 'Nginx', 'webserver', null, ['nginx'], [$h('server', 're:nginx(?:/([\d.]+))?')]],
            ['apache', 'Apache', 'webserver', null, ['apache http server', 'apache httpd', 'httpd'], [$h('server', 're:Apache(?:/([\d.]+))?')]],
            ['iis', 'IIS', 'webserver', null, ['microsoft iis', 'iis'], [$h('server', 're:Microsoft-IIS(?:/([\d.]+))?')]],
            ['tomcat', 'Tomcat', 'webserver', null, ['apache tomcat', 'tomcat'], []],
            ['caddy', 'Caddy', 'webserver', null, ['caddy'], [$h('server', 'Caddy')]],
            ['traefik', 'Traefik', 'webserver', null, ['traefik'], []],
            ['haproxy', 'HAProxy', 'webserver', null, ['haproxy'], []],
            ['cloudflare', 'Cloudflare', 'webserver', null, ['cloudflare'], [$h('cf-ray', 're:.+'), $ns('ns.cloudflare.com')]],
            ['akamai', 'Akamai', 'webserver', null, ['akamai'], [$h('server', 'AkamaiGHost'), $h('x-akamai-transformed', 're:.+')]],
            ['litespeed', 'LiteSpeed', 'webserver', null, ['litespeed'], [$h('server', 're:LiteSpeed(?:/([\d.]+))?'), $h('x-litespeed-cache', 're:.+')]],
            ['openresty', 'OpenResty', 'webserver', null, ['openresty'], [$h('server', 're:openresty(?:/([\d.]+))?')]],
            ['varnish', 'Varnish', 'webserver', null, ['varnish'], [$h('via', 'varnish'), $h('x-varnish', 're:.+')]],
            ['fastly', 'Fastly', 'webserver', null, ['fastly'], [$h('x-fastly-request-id', 're:.+'), $cname('fastly.net')]],
            ['cloudfront', 'Amazon CloudFront', 'webserver', null, ['cloudfront'], [$h('via', 'CloudFront'), $cname('cloudfront.net')]],

            ['postgresql', 'PostgreSQL', 'database', null, ['postgresql', 'postgres'], []],
            ['mysql', 'MySQL / MariaDB', 'database', null, ['mysql', 'mariadb'], []],
            ['sql-server', 'SQL Server', 'database', null, ['sql server', 'mssql'], []],
            ['oracle-db', 'Oracle Database', 'database', null, ['oracle database'], []],
            ['mongodb', 'MongoDB', 'database', null, ['mongodb'], []],
            ['redis', 'Redis', 'database', null, ['redis'], []],
            ['elasticsearch', 'Elasticsearch', 'database', null, ['elasticsearch', 'kibana'], []],

            ['docker', 'Docker', 'virtualization', null, ['docker'], []],
            ['kubernetes', 'Kubernetes', 'virtualization', null, ['kubernetes', 'k8s'], []],
            ['vmware', 'VMware vSphere / ESXi', 'virtualization', null, ['vmware', 'esxi', 'vcenter', 'vsphere'], []],
            ['hyper-v', 'Hyper-V', 'virtualization', null, ['hyper-v'], []],
            ['proxmox', 'Proxmox', 'virtualization', null, ['proxmox'], []],

            ['fortinet', 'Fortinet (FortiGate)', 'network', null, ['fortinet', 'fortigate', 'fortios', 'fortiproxy', 'fortimanager', 'fortiweb'], []],
            ['paloalto', 'Palo Alto', 'network', null, ['palo alto networks', 'pan-os', 'globalprotect'], []],
            ['cisco', 'Cisco (ASA / FTD)', 'network', null, ['cisco asa', 'cisco ftd', 'firepower', 'cisco ios xe', 'cisco secure firewall'], []],
            ['sonicwall', 'SonicWall', 'network', null, ['sonicwall'], []],
            ['checkpoint', 'Check Point', 'network', null, ['check point'], []],
            ['ivanti', 'Ivanti / Pulse Secure', 'network', null, ['ivanti', 'pulse secure', 'connect secure'], []],
            ['citrix', 'Citrix NetScaler', 'network', null, ['netscaler', 'citrix adc', 'citrix gateway', 'citrix bleed'], []],
            ['f5', 'F5 BIG-IP', 'network', null, ['big-ip', 'f5 big-ip'], [$h('server', 'BigIP')]],
            ['pfsense', 'pfSense / OPNsense', 'network', null, ['pfsense', 'opnsense'], []],

            ['m365', 'Microsoft 365', 'identity', null, ['microsoft 365', 'office 365', 'exchange online', 'microsoft outlook', 'outlook web access'], [$mx('mail.protection.outlook.com'), $spf('spf.protection.outlook.com'), $txt('MS=ms')]],
            ['google-workspace', 'Google Workspace', 'identity', null, ['google workspace', 'gmail'], [$mx('google.com'), $mx('googlemail.com'), $spf('_spf.google.com')]],
            ['active-directory', 'Active Directory', 'identity', null, ['active directory'], []],
            ['entra-id', 'Entra ID', 'identity', null, ['entra id', 'azure ad', 'azure active directory'], []],
            ['okta', 'Okta', 'identity', null, ['okta'], []],
            ['keycloak', 'Keycloak', 'identity', null, ['keycloak'], []],
            // Só "zoho mail": a Zoho também é dona do ManageEngine, muito citado em CVEs.
            ['zoho-mail', 'Zoho Mail', 'identity', null, ['zoho mail', 'zoho workplace'], [$mx('zoho.com'), $mx('zoho.eu'), $spf('zoho.com'), $spf('zohomail.com')]],
            ['proton-mail', 'Proton Mail', 'identity', null, ['proton mail', 'protonmail'], [$mx('protonmail.ch'), $spf('_spf.protonmail.ch')]],

            ['atlassian', 'Atlassian (Jira / Confluence)', 'tool', null, ['atlassian', 'jira', 'confluence'], [$txt('atlassian-domain-verification')]],
            ['slack', 'Slack', 'tool', null, ['slack'], []],
            ['teams', 'Microsoft Teams', 'tool', null, ['microsoft teams'], []],
            ['zoom', 'Zoom', 'tool', null, ['zoom video', 'zoom client', 'zoom workplace'], []],
            ['veeam', 'Veeam', 'tool', null, ['veeam'], []],
            ['sap', 'SAP', 'tool', null, ['sap netweaver', 'sap'], []],
            ['salesforce', 'Salesforce', 'tool', null, ['salesforce'], []],
            ['totvs', 'Totvs', 'tool', null, ['totvs', 'protheus'], []],
            ['servicenow', 'ServiceNow', 'tool', null, ['servicenow'], []],
            ['zendesk', 'Zendesk', 'tool', null, ['zendesk'], [$spf('mail.zendesk.com'), $html('static.zdassets.com')]],

            ['windows', 'Windows', 'endpoint', null, ['microsoft windows', 'windows 10', 'windows 11', 'windows'], []],
            ['macos', 'macOS', 'endpoint', null, ['macos'], []],
            ['android', 'Android', 'endpoint', null, ['android'], []],
            ['ios', 'iOS', 'endpoint', null, ['ios', 'iphone', 'ipados'], []],

            ['joomla', 'Joomla', 'cms', 'php', ['joomla'], [$html('re:<meta[^>]+generator[^>]+Joomla'), $gen('Joomla')]],
            ['magento', 'Magento / Adobe Commerce', 'cms', 'php', ['magento', 'adobe commerce'], [$cookie('mage-cache')]],
            ['shopify', 'Shopify', 'cms', null, ['shopify'], [$h('x-shopid', 're:.+'), $html('cdn.shopify.com'), $cname('myshopify.com')]],
            ['elementor', 'Elementor', 'cms', 'wordpress', ['elementor'], [$gen('Elementor'), $html('/wp-content/plugins/elementor/')]],
            ['woocommerce', 'WooCommerce', 'cms', 'wordpress', ['woocommerce'], [$gen('WooCommerce'), $html('/wp-content/plugins/woocommerce/')]],
            ['wix', 'Wix', 'cms', null, ['wix.com', 'wix'], [$gen('Wix.com'), $html('static.wixstatic.com')]],
            ['webflow', 'Webflow', 'cms', null, ['webflow'], [$gen('Webflow')]],

            // Criadores de sites e apps (inclusive com IA). O Lovable gera apps React.
            ['lovable', 'Lovable', 'builder', 'react', ['lovable.dev', 'lovable'], [
                $html('re:<meta[^>]+name="author"[^>]+content="Lovable"'), $html('Lovable Generated Project'),
                $html('lovable.dev/opengraph'), $script('gpteng.co'), $cname('lovable.app'),
            ]],

            ['sendgrid', 'SendGrid', 'email', null, ['sendgrid', 'twilio sendgrid'], [$spf('sendgrid.net')]],
            ['amazon-ses', 'Amazon SES', 'email', null, ['amazon ses'], [$spf('amazonses.com')]],
            ['mailchimp', 'Mailchimp', 'email', null, ['mailchimp'], [$spf('servers.mcsv.net')]],
            ['hubspot', 'HubSpot', 'email', null, ['hubspot'], [$html('js.hs-scripts.com'), $spf('hubspotemail.net')]],
            ['docusign', 'DocuSign', 'tool', null, ['docusign'], [$txt('docusign=')]],

            // Serviços de terceiros carregados no site: por eles chega um ataque de supply chain.
            ['google-tag-manager', 'Google Tag Manager', 'thirdparty', null, ['google tag manager'], [$script('googletagmanager.com')]],
            ['google-analytics', 'Google Analytics', 'thirdparty', null, ['google analytics'], [$script('google-analytics.com')]],
            ['recaptcha', 'reCAPTCHA', 'thirdparty', null, ['recaptcha'], [$html('recaptcha/api.js'), $html('google.com/recaptcha'), $script('recaptcha.net')]],
            ['meta-pixel', 'Meta Pixel', 'thirdparty', null, ['meta pixel', 'facebook pixel'], [$script('connect.facebook.net')]],
            ['hotjar', 'Hotjar', 'thirdparty', null, ['hotjar'], [$script('hotjar.com')]],
            ['ms-clarity', 'Microsoft Clarity', 'thirdparty', null, ['microsoft clarity'], [$script('clarity.ms')]],
            ['intercom', 'Intercom', 'thirdparty', null, ['intercom'], [$script('intercom.io'), $script('intercomcdn.com')]],
            ['jsdelivr', 'jsDelivr', 'thirdparty', null, ['jsdelivr'], [$script('jsdelivr.net')]],
            ['cdnjs', 'cdnjs', 'thirdparty', null, ['cdnjs'], [$script('cdnjs.cloudflare.com')]],
            ['unpkg', 'unpkg', 'thirdparty', null, ['unpkg'], [$script('unpkg.com')]],
            ['polyfill-io', 'Polyfill.io', 'thirdparty', null, ['polyfill.io'], [$script('polyfill.io')]],
            ['stripe', 'Stripe', 'thirdparty', null, ['stripe'], [$script('js.stripe.com')]],
            ['tiktok-pixel', 'TikTok Pixel', 'thirdparty', null, ['tiktok pixel'], [$script('analytics.tiktok.com')]],
            ['registro-br', 'Registro.br', 'dns', null, ['registro.br'], [$ns('dns.br')]],
            ['mailgun', 'Mailgun', 'email', null, ['mailgun'], [$spf('mailgun.org')]],
            ['postmark', 'Postmark', 'email', null, ['postmark'], [$spf('spf.mtasv.net')]],
            ['sparkpost', 'SparkPost', 'email', null, ['sparkpost'], [$spf('sparkpostmail.com')]],
            ['brevo', 'Brevo (Sendinblue)', 'email', null, ['brevo', 'sendinblue'], [$spf('sendinblue.com'), $spf('brevo.com')]],
            ['mandrill', 'Mandrill', 'email', null, ['mandrill'], [$spf('mandrillapp.com')]],
            ['rd-station', 'RD Station', 'email', null, ['rd station', 'rdstation'], [$spf('rdstation.com.br'), $html('d335luupugsy2.cloudfront.net')]],
        ];
    }
}
