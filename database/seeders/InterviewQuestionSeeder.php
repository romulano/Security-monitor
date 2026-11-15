<?php

namespace Database\Seeders;

use App\Models\InterviewQuestion;
use Illuminate\Database\Seeder;

/**
 * Perguntas iniciais da entrevista. As marcadas como de sistema alimentam
 * colunas do perfil (nível mínimo, marcas, setor...) e por isso não podem ser
 * apagadas nem mudar de tipo no admin. Só cria o que falta.
 */
class InterviewQuestionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->questions() as $position => $question) {
            if (InterviewQuestion::query()->where('key', $question['key'])->exists()) {
                continue;
            }

            InterviewQuestion::query()->create($question + [
                'position' => $position + 1,
                'is_system' => true,
                'active' => true,
            ]);
        }
    }

    private function questions(): array
    {
        $yesNo = [
            ['value' => 'sim', 'label' => 'Sim'],
            ['value' => 'nao', 'label' => 'Não'],
        ];

        return [
            // 1 · Empresa
            [
                'step' => 1, 'key' => 'alert_name', 'kind' => 'text',
                'prompt' => 'Nome deste alerta',
                'help' => 'Para diferenciar quando a empresa tem mais de um produto ou site. Ex.: Loja virtual, App, Portal do cliente.',
            ],
            [
                'step' => 1, 'key' => 'company_name', 'kind' => 'text', 'required' => true,
                'prompt' => 'Nome da empresa',
            ],
            [
                'step' => 1, 'key' => 'brands', 'kind' => 'tags',
                'prompt' => 'Marcas, produtos ou domínios que podem aparecer em notícias',
                'help' => 'Avisamos sempre que um desses nomes aparecer como vítima ou afetado, mesmo abaixo do nível mínimo que você escolher.',
            ],
            [
                'step' => 1, 'key' => 'sectors', 'kind' => 'multi',
                'prompt' => 'Setor de atuação',
                'help' => 'Entra nos alertas só se você ligar “notícias do meu setor” no passo 6.',
                'options' => [
                    ['value' => 'financeiro', 'label' => 'Financeiro', 'terms' => ['financ*', 'bank*', 'fintech', 'insurance', 'payment*']],
                    ['value' => 'saude', 'label' => 'Saúde', 'terms' => ['health*', 'hospital*', 'pharma*', 'medical']],
                    ['value' => 'varejo', 'label' => 'Varejo e e-commerce', 'terms' => ['retail', 'e-commerce', 'ecommerce']],
                    ['value' => 'governo', 'label' => 'Governo', 'terms' => ['government', 'public sector', 'defense']],
                    ['value' => 'educacao', 'label' => 'Educação', 'terms' => ['education', 'academia', 'universit*']],
                    ['value' => 'industria', 'label' => 'Indústria', 'terms' => ['manufactur*', 'industrial', 'automotive']],
                    ['value' => 'tecnologia', 'label' => 'Tecnologia / SaaS', 'terms' => ['technology', 'software*', 'it services', 'information technology', 'saas', 'cloud']],
                    ['value' => 'telecom', 'label' => 'Telecom', 'terms' => ['telecom*']],
                    ['value' => 'energia', 'label' => 'Energia e saneamento', 'terms' => ['energy', 'utilit*', 'oil and gas']],
                    ['value' => 'logistica', 'label' => 'Logística', 'terms' => ['logistics', 'transport*', 'shipping']],
                    ['value' => 'juridico', 'label' => 'Jurídico', 'terms' => ['legal', 'law firm*']],
                    ['value' => 'midia', 'label' => 'Mídia e entretenimento', 'terms' => ['media', 'entertainment', 'gaming']],
                ],
            ],
            [
                'step' => 1, 'key' => 'regions', 'kind' => 'multi',
                'prompt' => 'Onde vocês operam?',
                'help' => 'Ajuda a priorizar campanhas que miram essas regiões.',
                'options' => [
                    ['value' => 'brasil', 'label' => 'Brasil', 'codes' => ['BR']],
                    ['value' => 'latam', 'label' => 'América Latina', 'codes' => ['BR', 'AR', 'CL', 'CO', 'MX', 'PE', 'UY', 'PY', 'BO', 'EC', 'VE']],
                    ['value' => 'america_norte', 'label' => 'América do Norte', 'codes' => ['US', 'CA']],
                    ['value' => 'europa', 'label' => 'Europa', 'codes' => ['PT', 'ES', 'FR', 'DE', 'IT', 'GB', 'NL', 'BE', 'IE', 'PL', 'SE', 'NO', 'DK', 'FI', 'CH', 'AT']],
                    ['value' => 'asia', 'label' => 'Ásia-Pacífico', 'codes' => ['JP', 'KR', 'CN', 'IN', 'SG', 'AU', 'NZ', 'TW', 'HK', 'ID', 'VN', 'TH', 'MY', 'PH']],
                    ['value' => 'global', 'label' => 'Global', 'codes' => []],
                ],
            ],

            // 2 · Desenvolvimento
            [
                'step' => 2, 'key' => 'languages', 'kind' => 'catalog', 'catalog_category' => 'language',
                'prompt' => 'Quais linguagens de programação vocês usam?',
                'options' => [['value' => 'none', 'label' => 'Não desenvolvemos software']],
            ],
            [
                'step' => 2, 'key' => 'uses_framework', 'kind' => 'single',
                'prompt' => 'Usam algum framework?',
                'options' => array_merge($yesNo, [['value' => 'nao_sei', 'label' => 'Não sei']]),
            ],
            [
                'step' => 2, 'key' => 'frameworks', 'kind' => 'catalog', 'catalog_category' => 'framework',
                'filter_by' => 'languages', 'show_if' => ['key' => 'uses_framework', 'in' => ['sim']],
                'prompt' => 'Quais frameworks?',
                'help' => 'Mostramos os frameworks das linguagens que você marcou acima.',
            ],
            [
                'step' => 2, 'key' => 'devops', 'kind' => 'catalog', 'catalog_category' => 'devops',
                'prompt' => 'Onde fica o código e o CI/CD?',
                'help' => 'Vazamento de tokens e ataques a pipelines aparecem com frequência.',
                'options' => [['value' => 'none', 'label' => 'Nenhum']],
            ],

            // 3 · Infraestrutura
            [
                'step' => 3, 'key' => 'server_os', 'kind' => 'single',
                'prompt' => 'Tipo de servidor',
                'options' => [
                    ['value' => 'linux', 'label' => 'Linux', 'desc' => 'Ubuntu, Debian, RHEL e afins', 'techs' => ['linux']],
                    ['value' => 'windows', 'label' => 'Windows Server', 'desc' => 'IIS, Active Directory, RDP', 'techs' => ['windows-server']],
                    ['value' => 'both', 'label' => 'Ambos', 'desc' => 'parte Linux, parte Windows', 'techs' => ['linux', 'windows-server']],
                    ['value' => 'na', 'label' => 'Não se aplica', 'desc' => 'SaaS, serverless ou tudo terceirizado'],
                ],
            ],
            [
                'step' => 3, 'key' => 'linux_distros', 'kind' => 'catalog', 'catalog_category' => 'os',
                'filter_by' => 'server_os', 'show_if' => ['key' => 'server_os', 'in' => ['linux', 'both']],
                'prompt' => 'Distribuição Linux',
            ],
            [
                'step' => 3, 'key' => 'windows_versions', 'kind' => 'multi',
                'show_if' => ['key' => 'server_os', 'in' => ['windows', 'both']],
                'prompt' => 'Versão do Windows Server',
                'options' => array_map(fn ($v) => ['value' => $v, 'label' => $v, 'techs' => ['windows-server']], ['2016', '2019', '2022', '2025']),
            ],
            [
                'step' => 3, 'key' => 'cloud', 'kind' => 'catalog', 'catalog_category' => 'cloud',
                'prompt' => 'Nuvem e hospedagem',
                'options' => [
                    ['value' => 'on_prem', 'label' => 'Data center próprio'],
                    ['value' => 'na', 'label' => 'Não se aplica'],
                ],
            ],
            [
                'step' => 3, 'key' => 'web_servers', 'kind' => 'catalog', 'catalog_category' => 'webserver',
                'prompt' => 'Servidor web, proxy e CDN',
            ],
            [
                'step' => 3, 'key' => 'databases', 'kind' => 'catalog', 'catalog_category' => 'database',
                'prompt' => 'Bancos de dados',
            ],
            [
                'step' => 3, 'key' => 'virtualization', 'kind' => 'catalog', 'catalog_category' => 'virtualization',
                'prompt' => 'Containers e virtualização',
                'help' => 'ESXi e hipervisores são alvo frequente de ransomware.',
                'options' => [['value' => 'none', 'label' => 'Nenhum']],
            ],

            // 4 · Rede e ferramentas
            [
                'step' => 4, 'key' => 'edge', 'kind' => 'catalog', 'catalog_category' => 'network',
                'prompt' => 'Firewall, VPN e equipamentos de borda',
                'help' => 'É onde mais aparecem falhas exploradas em massa. Vale marcar mesmo se a gestão for terceirizada.',
                'options' => [['value' => 'unknown', 'label' => 'Não sei']],
            ],
            [
                'step' => 4, 'key' => 'identity', 'kind' => 'catalog', 'catalog_category' => 'identity',
                'prompt' => 'E-mail e identidade',
            ],
            [
                'step' => 4, 'key' => 'platforms', 'kind' => 'catalog', 'catalog_category' => 'cms,builder',
                'prompt' => 'Site, loja e plataformas',
                'help' => 'Onde o site, o blog ou a loja foram feitos, inclusive criadores com IA. Marque mesmo que outra empresa cuide deles.',
                'options' => [['value' => 'none', 'label' => 'Nenhum']],
            ],
            [
                'step' => 4, 'key' => 'tools', 'kind' => 'catalog', 'catalog_category' => 'tool,email',
                'prompt' => 'Ferramentas da empresa',
            ],
            [
                'step' => 4, 'key' => 'endpoints', 'kind' => 'catalog', 'catalog_category' => 'endpoint',
                'prompt' => 'Estações de trabalho e celulares',
                'options' => [['value' => 'linux_desktop', 'label' => 'Linux', 'techs' => ['linux']]],
            ],
            [
                'step' => 4, 'key' => 'custom_terms', 'kind' => 'tags',
                'prompt' => 'Algum produto que não apareceu?',
                'help' => 'Escreva como aparece nas notícias (ex.: “Totvs Protheus”). Cada termo vira busca no título, no resumo e nos produtos afetados.',
            ],

            // 5 · Domínio (o aceite e a prova de posse ficam na própria tela)
            [
                'step' => 5, 'key' => 'has_domain', 'kind' => 'single',
                'prompt' => 'Vocês têm um domínio próprio?',
                'options' => $yesNo,
            ],
            [
                'step' => 5, 'key' => 'domain', 'kind' => 'text',
                'show_if' => ['key' => 'has_domain', 'in' => ['sim']],
                'prompt' => 'Domínio principal',
                'help' => 'Também entra como marca: notícia que citar o domínio como vítima chega sempre.',
            ],

            // 6 · Alertas
            [
                'step' => 6, 'key' => 'min_criticity', 'kind' => 'single', 'required' => true,
                'prompt' => 'A partir de qual nível você quer ser avisado?',
                'options' => [
                    ['value' => 'critical', 'label' => 'Somente Critical', 'desc' => 'Exploração ativa, execução remota sem login, vazamento confirmado. Poucos por mês.'],
                    ['value' => 'high', 'label' => 'High e acima', 'desc' => 'Inclui falhas graves com correção disponível e campanhas ativas.', 'recommended' => true],
                    ['value' => 'medium', 'label' => 'Medium e acima', 'desc' => 'Mais volume: falhas com pré-requisitos e alertas gerais.'],
                    ['value' => 'low', 'label' => 'Todos', 'desc' => 'Inclui low. Combina melhor com o resumo diário.'],
                ],
            ],
            [
                'step' => 6, 'key' => 'attack_types', 'kind' => 'multi',
                'prompt' => 'Tipos de ameaça que mais interessam',
                'help' => 'Nenhum marcado = todos. Menções à sua empresa chegam sempre, de qualquer tipo.',
                'options' => [
                    ['value' => 'vulnerabilidades', 'label' => 'Vulnerabilidades e CVEs', 'signal' => 'cves', 'terms' => ['vulnerab*', 'remote_code_execution', 'rce', 'privilege_escalation', 'authentication_bypass', 'command_injection', 'sql_injection', 'ssrf', 'memory_corruption', 'zero_day*', 'zero_click', 'insecure_deserialization', 'csrf', 'xss', 'path_traversal', 'information_disclosure']],
                    ['value' => 'ransomware', 'label' => 'Ransomware', 'terms' => ['ransomware', 'extortion']],
                    ['value' => 'vazamento', 'label' => 'Vazamento de dados', 'signal' => 'data', 'terms' => ['data_breach', 'data_theft', 'data_leak*', 'data_exposure']],
                    ['value' => 'phishing', 'label' => 'Phishing e engenharia social', 'terms' => ['phishing', 'social_engineering', 'malvertising', 'account_takeover', 'credential_theft', 'smishing', 'vishing']],
                    ['value' => 'supply_chain', 'label' => 'Supply chain', 'terms' => ['supply_chain']],
                    ['value' => 'apt', 'label' => 'APT e espionagem', 'terms' => ['espionage', 'spyware', 'backdoor', 'apt']],
                    ['value' => 'fraude', 'label' => 'Fraude financeira', 'signal' => 'fraud', 'terms' => ['fraud*', 'banking_trojan', 'crypto_theft', 'bec']],
                    ['value' => 'ddos', 'label' => 'DDoS', 'terms' => ['denial_of_service', 'ddos']],
                ],
            ],
            [
                'step' => 6, 'key' => 'brand_mentions', 'kind' => 'boolean',
                'prompt' => 'Notícias que citam a minha empresa ou marcas',
                'help' => 'Em qualquer nível. Usa o nome, as marcas e o domínio informados.',
            ],
            [
                'step' => 6, 'key' => 'sector_news', 'kind' => 'boolean',
                'prompt' => 'Notícias do meu setor',
                'help' => 'Mesmo sem afetar a sua stack. Aumenta bastante o volume.',
            ],
            [
                'step' => 6, 'key' => 'case_updates', 'kind' => 'boolean',
                'prompt' => 'Atualizações de casos já alertados',
                'help' => 'Quando surge fonte nova ou a criticidade sobe.',
            ],
            [
                'step' => 6, 'key' => 'language', 'kind' => 'single',
                'prompt' => 'Idioma dos alertas',
                'help' => 'O Security Monitor já gera cada análise em português e inglês.',
                'options' => [
                    ['value' => 'pt', 'label' => 'Português'],
                    ['value' => 'en', 'label' => 'English'],
                ],
            ],
            [
                'step' => 6, 'key' => 'versions', 'kind' => 'versions',
                'prompt' => 'Versões do que vocês usam',
                'help' => 'Opcional. Com a versão, a IA sabe se uma falha atinge mesmo vocês, por exemplo uma que só existe no PHP 8.3 quando vocês usam o 8.2. Deixe em branco o que não souber.',
            ],
        ];
    }
}
