<?php

use App\Services\Pentest\Hypotheses\HypothesisPhase;
use App\Services\Pentest\Mapping\MappingPhase;
use App\Services\Pentest\Recon\ActiveRecon;
use App\Services\Pentest\Recon\PassiveRecon;
use App\Services\Pentest\Scope\ScopePhase;

return [

    /*
    |--------------------------------------------------------------------------
    | Elasticsearch
    |--------------------------------------------------------------------------
    */
    'elasticsearch' => [
        'host' => env('ELASTICSEARCH_HOST', 'http://es:9200'),
        'index' => env('ELASTICSEARCH_INDEX', 'secmon_cases'),
        'username' => env('ELASTICSEARCH_USERNAME'),
        'password' => env('ELASTICSEARCH_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Crawler
    |--------------------------------------------------------------------------
    | Estes são os defaults. A tabela `settings` (editável pelo admin) tem
    | precedência — veja App\Support\Settings.
    */
    'crawler' => [
        'enabled' => env('CRAWLER_ENABLED', true),
        'user_agent' => env('CRAWLER_USER_AGENT', 'SecurityMonitorBot/1.0 (+https://example.com)'),
        'timeout' => (int) env('CRAWLER_TIMEOUT', 25),
        'max_items_per_source' => (int) env('CRAWLER_MAX_ITEMS_PER_SOURCE', 25),
        'dedup_threshold' => (float) env('CRAWLER_DEDUP_THRESHOLD', 0.62),
        'dedup_window_days' => 14,
        // Fonte nova só traz do feed o que saiu até N dias antes do cadastro.
        'backfill_days' => 7,
        'max_content_chars' => 24000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Enriquecimento por IA
    |--------------------------------------------------------------------------
    */
    'enricher' => [
        'enabled' => env('ENRICHER_ENABLED', true),
        'driver' => env('ENRICHER_DRIVER', 'claude'),
        // Atualização por trechos: na matéria que já existe, a análise devolve só as
        // partes do relatório que o artigo muda (em inglês e em português), em vez do
        // relatório inteiro duas vezes. Desligada, vale o relatório inteiro.
        'case_patch' => (bool) env('ENRICHER_CASE_PATCH', true),

        'claude' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
            // `low` basta: a tarefa é extração estruturada de um texto curto.
            // Vazio não manda o parâmetro (o padrão do modelo; o Haiku 4.5 recusa).
            'effort' => env('ANTHROPIC_EFFORT', 'low'),
            'base_url' => rtrim((string) env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
            // Teto da resposta (raciocínio, texto entre as buscas e o JSON). Era 16 mil;
            // com a pesquisa na web (#165) algumas análises passaram disso e caíam na
            // heurística (06/11). Teto não é cobrança: paga-se só o que o modelo escreve.
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 24000),
            // Pesquisa na web na análise: a busca e a leitura de páginas da API
            // (rodam na Anthropic). Teto por artigo, porque cada busca é cobrada
            // à parte; a leitura conta só os tokens da página.
            'research' => (bool) env('ANTHROPIC_RESEARCH', true),
            'research_searches' => (int) env('ANTHROPIC_RESEARCH_SEARCHES', 5),
            'research_fetches' => (int) env('ANTHROPIC_RESEARCH_FETCHES', 8),
            // Teto de tokens de cada página lida na pesquisa: um boletim ou um PR do
            // GitHub entraria inteiro (às vezes passa de 30 mil tokens), e cada um é
            // cobrado como entrada. 0 = sem teto.
            'research_fetch_tokens' => (int) env('ANTHROPIC_RESEARCH_FETCH_TOKENS', 12000),
            // Opções do select em Admin → Configurações quando a Models API não
            // pode ser consultada (sem chave). Com chave, a lista vem ao vivo.
            'models' => [
                'claude-fable-5-1' => 'Claude Fable 5.1',
                'claude-opus-5-5' => 'Claude Opus 5.5',
                'claude-opus-5' => 'Claude Opus 5',
                'claude-opus-4-8' => 'Claude Opus 4.8',
                'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
                'claude-sonnet-5' => 'Claude Sonnet 5',
                'claude-haiku-5-5' => 'Claude Haiku 5.5',
                'claude-haiku-4-5' => 'Claude Haiku 4.5',
            ],
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
            'max_tokens' => 16000,
            // Tempo de cada chamada (a análise, o parecer do revisor e a refação).
            'timeout' => (int) env('OPENAI_TIMEOUT', 120),
        ],

        // OpenRouter: os modelos de vários fornecedores atrás de uma chave só, no protocolo
        // da OpenAI. O modelo se escolhe em Admin → Configurações (a lista vem do /models do
        // OpenRouter); estes ficam no select quando a lista não pode ser consultada.
        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'model' => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
            'base_url' => rtrim((string) env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'), '/'),
            'max_tokens' => (int) env('OPENROUTER_MAX_TOKENS', 16000),
            // Tempo de cada chamada (a análise, o parecer do revisor e a refação). Mais que
            // o da OpenAI: o OpenRouter põe a chamada na fila do provedor escolhido.
            'timeout' => (int) env('OPENROUTER_TIMEOUT', 180),
            'models' => [
                'anthropic/claude-sonnet-4.5' => 'Anthropic: Claude Sonnet 4.5',
                'anthropic/claude-haiku-4.5' => 'Anthropic: Claude Haiku 4.5',
                'openai/gpt-4o-mini' => 'OpenAI: GPT-4o-mini',
                'openai/gpt-4.1' => 'OpenAI: GPT-4.1',
                'google/gemini-2.5-pro' => 'Google: Gemini 2.5 Pro',
                'google/gemini-2.5-flash' => 'Google: Gemini 2.5 Flash',
                'deepseek/deepseek-chat' => 'DeepSeek: DeepSeek V3',
                'meta-llama/llama-3.3-70b-instruct' => 'Meta: Llama 3.3 70B Instruct',
                'qwen/qwen-2.5-72b-instruct' => 'Qwen: Qwen2.5 72B Instruct',
                'mistralai/mistral-large' => 'Mistral: Mistral Large',
            ],
        ],

        // Ponte para o Claude Code da máquina (~/job/hermes). O endereço padrão
        // é o gateway da rede deste compose — é nele que o hermes escuta.
        'hermes' => [
            'url' => rtrim((string) env('HERMES_URL', 'http://172.31.88.1:8765'), '/'),
            'token' => env('HERMES_TOKEN'),
            'model' => env('HERMES_MODEL', 'sonnet'),
            'effort' => env('HERMES_EFFORT', 'low'),
            // Esforço das chamadas de decisão (juiz dos alertas, triagem e parecer do
            // GitHub, parecer do Radar, confirmação da fusão, tradução das respostas
            // da entrevista, explicação de erro): uma nota ou um rótulo, sem precisar
            // do raciocínio longo do padrão do modelo. Vazio = o padrão do hermes.
            'light_effort' => env('HERMES_LIGHT_EFFORT', 'low'),
            // Aliases do Claude Code (sempre a versão mais nova da família). O
            // /v1/models do hermes não lista o `fable`, mas o CLI aceita.
            'aliases' => ['opus', 'sonnet', 'haiku', 'fable'],
            // Abaixo do $timeout do EnrichArticle (300s), para o job não morrer antes.
            'timeout' => (int) env('HERMES_TIMEOUT', 240),
            // Pesquisa na web na análise de cada artigo: o boletim do fornecedor,
            // o GHSA, o PR da correção. O hermes só libera WebSearch e WebFetch
            // por chamada (o artigo é texto de terceiros); cada busca ou página
            // lida gasta um turno, e ~20 a 60 s a mais por artigo.
            'research' => (bool) env('HERMES_RESEARCH', true),
            'research_turns' => (int) env('HERMES_RESEARCH_TURNS', 12),
            // Esforço mínimo com a pesquisa: no `low` o modelo lê o boletim e
            // para; no `medium` abre os releases e o PR da correção (teste de
            // 05/11 com o GHSA-63hm-g96r-2m2q do Laravel, ~35 s e o mesmo custo).
            'research_effort' => env('HERMES_RESEARCH_EFFORT', 'medium'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chaves expostas (a varredura dos domínios comprovados)
    |--------------------------------------------------------------------------
    */
    'keys' => [
        // O índice (CDX) do Web Archive: o recon passivo, os endereços que o archive.org já viu do domínio.
        'archive_url' => env('KEYS_ARCHIVE_URL', 'https://web.archive.org/cdx/search/cdx'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Refazer a matéria com a IA (texto manual, Admin → Coletas)
    |--------------------------------------------------------------------------
    | Até `full_max_articles` artigos a matéria é refeita do zero, um artigo por
    | job. Acima disso só o artigo que pediu o refazer (e os da matéria que
    | ficaram sem a IA) é relido contra a matéria atual: refazer uma matéria de
    | 100 fontes leva horas de IA. O refazer completo dela fica no
    | `secmon:reanalyze --case=<uuid>`.
    */
    'rebuild' => [
        'full_max_articles' => (int) env('REBUILD_FULL_MAX_ARTICLES', 8),
        // Estimativa mostrada antes do refazer completo de uma matéria grande.
        'minutes_per_article' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Revisor do relatório (Admin → Prompts, "Revisor do relatório")
    |--------------------------------------------------------------------------
    | Depois que a análise escreve ou reescreve o relatório e antes de ele ir ao
    | ar, a IA (hermes) confere o texto contra a fonte e pode pedir à análise que
    | refaça uma parte ou tudo, até `max_adjustments` vezes por versão. A etapa
    | nasce desligada: quem liga é o admin, na aba Prompts.
    */
    'review' => [
        // Pedidos de ajuste por versão; depois deles, o parecer do revisor é o final.
        'max_adjustments' => (int) env('REVIEW_MAX_ADJUSTMENTS', 2),
        // Cada parecer (a resposta é curta; a fonte e o rascunho são longos).
        'timeout' => (int) env('REVIEW_TIMEOUT', 180),
        // Tempo do revisor (pareceres e refações) dentro do job da análise. O job ganha
        // esse tanto de limite com a etapa ligada; sem tempo para a próxima chamada,
        // a matéria sai com a última versão.
        'budget' => (int) env('REVIEW_BUDGET', 1200),
        // Relatório publicado que o revisor lê como fonte na atualização.
        'report_chars' => 20000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Grupos de indicadores
    |--------------------------------------------------------------------------
    | Taxonomia dos indicadores. A ordem aqui é a ordem de
    | exibição na matéria e o `field` é o nome usado na busca (ex.: cves:CVE-*).
    */
    'indicator_fields' => [
        'cves' => 'CVEs',
        'iocs' => 'IOCs',
        'malware_families' => 'Malware Families',
        'ttps_attack' => 'ATT&CK / TTPs',
        'threat_actors' => 'Threat Actors',
        'victim_sectors' => 'Victim Sectors',
        'victim_orgs' => 'Victim Organizations',
        'person_names' => 'People',
        'affected_products' => 'Affected Products',
        'vendors' => 'Vendors',
        'attack_type' => 'Attack Types',
        'initial_access_vector' => 'Initial Access',
        'countries' => 'Countries',
        'countries_code' => 'Country Codes',
        'loss_type' => 'Loss Types',
        'fraud_type' => 'Fraud Types',
        'data_types_exposed' => 'Data Exposed',
        // CVEs só citadas como contexto (outro fornecedor de passagem, histórico):
        // ficam na matéria e na busca `related_cves:`, mas não em `cves`, que
        // alimenta alertas, a busca `cves:` e o selo de exploit.
        'related_cves' => 'Related CVEs',
        'researchers' => 'Researchers',
        'authors' => 'Authors',
        'source_confidence' => 'Source Confidence',
    ],

    'criticities' => ['critical', 'high', 'medium', 'low'],

    /*
    |--------------------------------------------------------------------------
    | Alertas personalizados
    |--------------------------------------------------------------------------
    | Defaults; a tabela `settings` (Admin → Alertas) tem precedência. As
    | credenciais dos apps do Slack, do Discord e do Telegram ficam só no .env, como as chaves de IA.
    | O webhook não tem credencial do Security Monitor: o segredo de cada um fica no banco, criptografado.
    */
    'alerts' => [
        'enabled' => env('ALERTS_ENABLED', true),
        'realtime_enabled' => true,
        'digest_enabled' => true,
        'discovery_enabled' => true,
        'digest_default_time' => '07:00',
        'default_timezone' => 'America/Sao_Paulo',
        // O resumo que perdeu o horário (worker fora do ar) ainda sai até
        // esta quantidade de horas depois; passou disso, o dia é pulado.
        'digest_grace_hours' => 4,
        'max_per_hour' => 10,
        'min_score' => 40,
        // Antes de sair na hora, o alerta passa pela IA (hermes), que lê a
        // notícia com o perfil e as avaliações do cliente: só vai se a
        // relevância ficar acima do corte.
        'ai_review_enabled' => env('ALERTS_AI_REVIEW', true),
        'ai_review_threshold' => 70,
        'discovery_every_days' => 30,
        // v3 (20/10): inclui as 3 páginas abertas por navegador como referência do Radar de domínios.
        'consent_version' => 'v3',
        'profiles_index' => env('ELASTICSEARCH_PROFILES_INDEX', 'secmon_profiles'),

        'slack' => [
            'client_id' => env('SLACK_CLIENT_ID'),
            'client_secret' => env('SLACK_CLIENT_SECRET'),
            'timeout' => 10,
        ],

        'discord' => [
            'client_id' => env('DISCORD_CLIENT_ID'),
            'client_secret' => env('DISCORD_CLIENT_SECRET'),
            'bot_token' => env('DISCORD_BOT_TOKEN'),
            // Servidor do Security Monitor no Discord. O Discord só deixa o bot mandar mensagem
            // direta para quem divide um servidor com ele: quem escolhe DM entra aqui.
            'home_guild_id' => env('DISCORD_HOME_GUILD_ID'),
            'timeout' => 10,
        ],

        'telegram' => [
            // Token do @BotFather. O bot recebe o /start de quem conecta por
            // long polling (secmon:telegram-updates, no supervisor): sem webhook público.
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            'bot_username' => env('TELEGRAM_BOT_USERNAME', 'SecurityMonitorDevBot'),
            // Quanto tempo vale o link de conexão (t.me/<bot>?start=<código>).
            'link_ttl_minutes' => 30,
            'timeout' => 10,
        ],

        'webhook' => [
            // Webhooks por conta (cada um com nome, tipos, pausa e segredo próprios).
            'max_per_user' => 5,
            // Espera da conexão e da resposta inteira, em segundos: um endereço lento não segura a fila.
            'connect_timeout' => 3,
            'timeout' => 10,
            // Bytes da resposta que o Security Monitor lê (e descarta): só o código HTTP importa.
            'max_response_bytes' => 4096,
            // Entregas seguidas que falharam (sem contar o teste) até o envio parar e o cliente ser avisado por e-mail.
            'max_failures' => 5,
            // Teto do Retry-After de um 429: mais que isso, a entrega espera só este tanto.
            'max_retry_after' => 1800,
        ],

        'discovery' => [
            'timeout' => 20,
            'max_redirects' => 3,
            // Teto de requisições HTTP ao site por varredura, contando redirects:
            // página inicial (+1 redirect, ex.: para o www), página de erro e blog (+1).
            'max_requests' => 5,
            // A página inicial é lida inteira; o teto só existe para um site
            // que devolva um arquivo gigante não derrubar o worker.
            'max_bytes' => 10 * 1024 * 1024,
            // Itens fora do catálogo criados por execução (o resto fica para a próxima).
            'max_auto_items' => 60,
            // IPs de saída do Security Monitor. Preenchidos, a regra de liberação sugerida
            // ao cliente (Cloudflare) passa a exigir o IP além do User-Agent.
            'egress_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ALERTS_DISCOVERY_EGRESS_IPS', ''))))),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Posse de domínio (uma só para a conta)
    |--------------------------------------------------------------------------
    | O registro TXT secmon-verify=<token da conta> no próprio nome (nunca o que chega por
    | um CNAME) ou num domínio de cima, até o principal, que cobre os subdomínios. Comprovado
    | num serviço, vale em todos (Notícias, Radar, monitor de sites e webhook); a stack de
    | testes lê a mesma prova e confere de novo ao vivo, com as regras dela.
    | Ver App\Services\Domains\DomainOwnership.
    */
    /*
    |--------------------------------------------------------------------------
    | Subdomínios da empresa: a descoberta passiva (E3c)
    |--------------------------------------------------------------------------
    | Uma vez por dia por domínio comprovado da empresa, os certificados públicos
    | (crt.sh) e o DNS do Security Monitor: o que resolve e está na zona da empresa entra
    | ativo até o teto; o resto vira sugestão com o motivo. Fila `subdomains`,
    | no fim do worker de baixa prioridade. Ver App\Services\Domains\SubdomainDiscovery.
    */
    'subdomains' => [
        'discovery_enabled' => env('SUBDOMAINS_DISCOVERY_ENABLED', true),
        // Domínios por rodada do agendador (de hora em hora): espalha o crt.sh pelo dia.
        'per_run' => 30,
        'every_hours' => 24,
        // Nomes do crt.sh lidos por domínio, e quantos nomes novos o DNS confere por execução.
        'max_ct_names' => 500,
        'new_per_run' => 100,
        // O job para de conferir nomes novos neste tempo (o worker mata em 150 s); o resto fica para o dia seguinte.
        'budget_seconds' => 110,
        // Sugestões guardadas por domínio: acima disso, o nome não é gravado.
        'max_suggested' => 200,
        // O subdomínio ativo sem endereço por estes dias sai (`gone`) e libera a vaga.
        'gone_after_days' => 7,
        // O nome dos certificados que não resolve só é conferido de novo depois destes dias.
        'skip_days' => 7,
    ],

    'ownership' => [
        // O domínio esperando o TXT é conferido a cada 10 minutos, por este tempo.
        'verify_window_hours' => 72,
        // A posse comprovada é conferida de novo uma vez neste intervalo...
        'recheck_hours' => 24,
        // ... e cai quando o TXT fica este tempo sem aparecer (o DNS mudo conta junto).
        'grace_days' => 7,
        // Reconferências por rodada do agendador (a cada 10 minutos).
        'recheck_batch' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoria de segurança dos sites do admin (Admin → Segurança)
    |--------------------------------------------------------------------------
    | Só leitura e sem intrusão: GET, DNS e um handshake TLS, sempre pelo SafeFetcher/IpGuard
    | (nada de IP interno), e nunca se guarda o corpo da página, valor de cookie ou o
    | certificado inteiro. A exposição de arquivos (/.env, /.git...) só é conferida em site
    | com posse comprovada.
    */
    'security' => [
        'max_sites' => 30,
        // Intervalo padrão e mínimo entre varreduras de um mesmo site.
        'interval_minutes' => 360,
        'min_interval_minutes' => 60,
        // "Verificar agora": no máximo uma vez por hora por site.
        'manual_cooldown_minutes' => 60,
        // O agendador parou? Sem varredura há mais que isso, o painel fica vermelho.
        'stale_after_hours' => 48,
        'retention_days' => 365,
        // Requisições por varredura (teto do SafeFetcher), tempo por requisição e bytes lidos.
        'request_budget' => 25,
        'timeout' => 8,
        'home_bytes' => 262144,
        'probe_bytes' => 4096,
        // Endereços do servidor do Security Monitor: site que resolve só para eles tem posse automática
        // (os que estão atrás da Cloudflare provam a posse por TXT, secmon-verify=<token>).
        'own_ips' => array_values(array_unique(array_filter(array_map('trim', array_merge(
            explode(',', (string) env('SECURITY_OWN_IPS', '')),
            explode(',', (string) env('ALERTS_DISCOVERY_EGRESS_IPS', '')),
        ))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Endereços do próprio Security Monitor
    |--------------------------------------------------------------------------
    | O IP público do servidor (e a faixa IPv6 dele) e os de saída. O navegador do
    | Radar nunca abre página neles: pelo IP, sem passar pela Cloudflare, a conexão
    | cai na borda que serve os sites de todos os stacks do servidor. Ele recebe a
    | lista em cada lote (o container não tem .env). IP ou faixa (CIDR), por vírgula;
    | os da monitoria e de saída entram sozinhos.
    */
    'own_addresses' => array_values(array_unique(array_filter(array_map('trim', array_merge(
        explode(',', (string) env('SECMON_OWN_ADDRESSES', '')),
        explode(',', (string) env('SECURITY_OWN_IPS', '')),
        explode(',', (string) env('ALERTS_DISCOVERY_EGRESS_IPS', '')),
    ))))),

    /*
    |--------------------------------------------------------------------------
    | Testes de segurança (teste ativo, só com autorização)
    |--------------------------------------------------------------------------
    | A stack de testes ativos fica desligada por padrão em tudo. A posse é a do
    | Radar (o mesmo TXT), numa cópia própria do domínio. Quem enxerga a área:
    | `allowed` (do ambiente; o admin não liga). Quem usa: a conta que o admin
    | liberou em Admin → Usuários (users.pentest_access_granted_at), e a de admin.
    */
    // Verificação em duas etapas (app autenticador + códigos de recuperação). Desligada, a tela de
    // configurar e a do código no login respondem 404, e o login não pede o código a ninguém.
    'two_factor' => [
        'enabled' => (bool) env('TWO_FACTOR_ENABLED', true),
        // O admin do Security Monitor só usa o /admin com a 2FA ligada; sem ela, cada página do admin leva à tela de
        // configurar. Desligada por padrão: o deploy não muda nada para o dono, que liga depois de configurar.
        'admin_required' => (bool) env('TWO_FACTOR_ADMIN_REQUIRED', false),
        'issuer' => 'Security Monitor',
    ],

    'pentest' => [
        'allowed' => (bool) env('PENTEST_ALLOWED', false),
        // A chave global (o admin liga, e só com as pré-condições abaixo) e a aprovação da 1ª execução (A6).
        'enabled' => (bool) env('PENTEST_ENABLED', false),
        'require_approval' => (bool) env('PENTEST_REQUIRE_APPROVAL', true),
        // Pré-condições da chave: fatos que só o dono registra (datas no formato 2026-11-10).
        'terms_published_version' => env('PENTEST_TERMS_PUBLISHED_VERSION'),
        'terms_published_at' => env('PENTEST_TERMS_PUBLISHED_AT'),
        'notice_given_at' => env('PENTEST_NOTICE_GIVEN_AT'),
        'notice_days' => (int) env('PENTEST_NOTICE_DAYS', 30),
        'legal_opinion_at' => env('PENTEST_LEGAL_OPINION_AT'),
        // Desde quando um e-mail em abuse@ chama o admin no Telegram ou no Discord (Blue Team, Q8).
        'abuse_alert_at' => env('PENTEST_ABUSE_ALERT_AT'),
        // IPs que a stack nunca testa, além dos do Security Monitor e da saída (IP ou faixa, vírgula).
        'forbidden_addresses' => explode(',', (string) env('PENTEST_FORBIDDEN_ADDRESSES', '')),
        // Domínios por conta (o passo 1 da autorização avisa).
        'max_domains' => 3,
        // Execuções por conta: uma por domínio a cada 24 h e até 4 por semana. As canceladas e as
        // recusadas não contam. Na semana, também não conta a que terminou sem testar o site, e o admin
        // pode liberar um reteste fora das 24 h (item 6 do Blue Team). O Blue Team pode mudar só os números.
        'runs_per_domain_per_day' => (int) env('PENTEST_RUNS_PER_DOMAIN_PER_DAY', 1),
        'runs_per_week' => (int) env('PENTEST_RUNS_PER_WEEK', 4),
        // O teto por site, somando todas as contas (item 2 do Blue Team, rodada 4): protege o servidor do
        // cliente quando duas contas miram o mesmo host (agência e cliente, ou multiconta). Não conta o
        // que não testou o site nem o que o admin liberou (o reteste).
        'runs_per_host_per_day' => (int) env('PENTEST_RUNS_PER_HOST_PER_DAY', 2),
        'runs_per_host_per_week' => (int) env('PENTEST_RUNS_PER_HOST_PER_WEEK', 6),
        // A posse durante a execução (termo, seção 6): reconferida no começo de cada fase e a cada 15
        // minutos; com o DNS mudo, tenta de novo a cada 5 minutos e, depois de 20, para.
        'ownership_recheck_minutes' => 15,
        'ownership_unknown_retry_minutes' => 5,
        'ownership_unknown_stop_minutes' => 20,
        // O trabalho de cada fase da execução (fase => classe que implementa PhaseWork). Enquanto faltar o
        // de alguma, o portão não deixa nenhuma execução começar: ela espera agendada e expira, nunca
        // "conclui com 0 achados". O recon passivo entrou na P9a, a lista de alvos (`scope`) na P9b, o recon ativo
        // (as portas) e o mapeamento na P10, as hipóteses na P11; as outras fases chegam depois.
        'phases' => [
            'recon_passive' => PassiveRecon::class,
            'scope' => ScopePhase::class,
            'recon_active' => ActiveRecon::class,
            'mapping' => MappingPhase::class,
            'hypotheses' => HypothesisPhase::class,
        ],
        // O recon passivo (06 §3.1, P9a): o DNS pelo resolvedor do servidor, a 10 consultas por segundo e até
        // 1.000 por execução; até 500 nomes do crt.sh e 200 nomes resolvidos (o resto fica nas limitações), em
        // lotes de 50 por job; 3 rótulos aleatórios para achar o curinga, em até 10 níveis; cadeias de CNAME de
        // até 8 saltos.
        'recon' => [
            'dns_per_second' => 10,
            'max_dns_queries' => 1000,
            'max_ct_names' => 500,
            'max_names' => 200,
            'batch_names' => 50,
            'wildcard_labels' => 3,
            'max_wildcard_levels' => 10,
            'cname_max_hops' => 8,
            'max_ips_per_name' => 16,
            'max_ct_bytes' => 16 * 1024 * 1024,
            // A reserva do orçamento de DNS (decisão 4 do coordenador na P9b): a lista de rótulos só gasta o que sobra
            // depois de 4 consultas por nome conhecido (o domínio, o DNS dele e os certificados, até o teto de nomes) e
            // de 200 para o DNS reverso. Cada rótulo achado reserva as 4 consultas dele nos nomes.
            'labels_reserve_per_name' => 6,
            'labels_reserve_reverse' => 200,
            // O teto da lista de rótulos do item `sup.dns_rotulos` (B3 do Red Team na P9a): sem repetição, até 150.
            'max_labels' => 150,
        ],
        // A lista de alvos (06 §7.3, P9b): o arquivo das faixas dos provedores, do Red Team, por PR e sem download em
        // produção (até 8 MiB; com mais de 30 dias, nada vira `cliente`); o RDAP dos IPs que nada mais classificou, até 16
        // por execução, 6 por vez, com 10 s cada consulta.
        'scope' => [
            'provider_ranges_path' => resource_path('pentest/provider-ranges.json'),
            'provider_ranges_max_bytes' => 8 * 1024 * 1024,
            'provider_ranges_max_age_days' => 30,
            'max_rdap_ips' => 16,
            'rdap_batch' => 6,
            'rdap_timeout' => 10,
        ],
        // O vínculo de cada par com o cliente (A7, P9c): um handshake TLS com o SNI do nome em até 5 nomes por IP, e um sem
        // SNI em até 16 IPs que ainda podem virar dedicados (`tls.vinculo` e `tls.vinculo_sem_sni`, do catálogo); uma
        // rodada a mais só para os que não responderam. A cadeia é conferida no PHP, pelo pacote de CAs do servidor (o
        // sha256 dele vai para a execução). O vínculo vale 2 h, e nunca depois de uma pausa (regra 21 do Red Team). Os
        // tetos do Red Team na P9b (pergunta 2: quem controla uma zona filha escolhe os IPs dos nomes dela): até 64 IPs
        // distintos por execução, 4 por nome, e 16 abertos por nomes de zona filha com NS de fora do domínio.
        'binding' => [
            'items' => ['sni' => 'tls.vinculo', 'no_sni' => 'tls.vinculo_sem_sni'],
            'names_per_ip' => 5,
            'ips_per_name' => 4,
            'max_ips' => 64,
            'max_child_zone_ips' => 16,
            'max_no_sni_ips' => 16,
            'valid_minutes' => 120,
            'ca_bundle' => env('PENTEST_CA_BUNDLE', '/etc/ssl/certs/ca-certificates.crt'),
        ],
        // O recon ativo (Red Team §3.4, P10): as portas da lista do catálogo (`sup.portas_tcp`, conexão TCP completa e o
        // banner que o serviço mostra sozinho), só nos servidores dedicados ao domínio, até 16 por execução. O servidor que
        // virou dedicado só pelo certificado padrão (sem o DNS reverso confirmado) tem só as portas de web (R2 do Red Team
        // na P9c: o 1º site de um VPS de agência). Duas requisições por porta (a conexão e o banner), uma rodada a mais só
        // para o que não respondeu. Com 3/4 ou mais das portas abertas, é armadilha (tarpit): inconclusivo.
        'recon_active' => [
            'item' => 'sup.portas_tcp',
            'ports_data' => 'portas-tcp',
            'max_ips' => 16,
            'requests_per_port' => 2,
            'web_ports' => [80, 443, 3000, 4443, 5000, 8000, 8008, 8080, 8081, 8443, 8888, 9000, 9090],
        ],
        // O mapeamento (Red Team §3.5, P10b): a navegação só de leitura de cada nome (`sup.mapeamento`), até 30 nomes e 300
        // páginas por nome, com 40% do orçamento de requisições da execução dividido entre eles; em pedidos de até 12 min
        // no ritmo do mapeamento. O disjuntor do WAF (L12, T4): espera 15 min depois do bloqueio do IP ou do 2º limite
        // (até 2 vezes de novo; depois, barrado pelo WAF; o bloqueio conta por IP, para todos os nomes dele), e 15 e
        // depois 30 min depois de falhas seguidas (na 3ª, instável).
        'mapping' => [
            'item' => 'sup.mapeamento',
            'max_hosts' => 30,
            'max_urls' => 300,
            'budget_share' => 0.4,
            'request_seconds' => 720,
            'cooldown_minutes' => [15, 30],
            'max_waf_retries' => 2,
        ],
        // As hipóteses (P11): as regras escolhem os itens do catálogo de cada alvo, e o portão de hipóteses decide cada um
        // (`pentest_units`). Até tantas unidades por execução (30 nomes com os itens de nome passam de 1.000); o resto fica
        // de fora, contado na auditoria. Os testes ficam com até 80% do orçamento de requisições que sobra depois do
        // mapeamento, na ordem das hipóteses (T3); o resto é da revalidação (P12).
        'hypotheses' => [
            'max_units' => 2000,
            'budget_share' => 0.8,
        ],
        // A IA das hipóteses (P11c, 06 §8): só reordena as planejadas, pseudonimizada, na fila `pentest-ai` (o worker
        // `fila-analise`, depois da `judge`). Até 4 chamadas ao hermes por execução e 30 por dia; sem vaga na fila em 20
        // minutos, o passo segue sem ela. Desligada (PENTEST_AI=false, ou a etapa na aba Prompts), vale a ordem das regras.
        'ai' => [
            'enabled' => env('PENTEST_AI', true),
            'queue' => 'pentest-ai',
            'max_calls_per_run' => 4,
            'max_calls_per_day' => 30,
            'wait_minutes' => 20,
        ],
        // Os passos (fila `pentest`, worker `fila-pentest`): até 120 s cada no PHP; sem batimento por 4
        // minutos, o vigia redespacha (um deploy matou o job); 3 falhas seguidas do mesmo passo levam a
        // execução a `failed`; parando, o vigia espera até 2 minutos o que está em voo.
        'step_budget_seconds' => 120,
        'step_stale_minutes' => 4,
        'step_max_failures' => 3,
        'stopping_grace_minutes' => 2,
        // A faixa do teste em toda página (Estados-gerais): o agendado aparece nas horas antes do início, e o
        // parado fica até a pessoa abrir a execução, ou por estas horas. As telas consultam a situação de tempos
        // em tempos (segundos): a página da execução e a faixa.
        'banner_scheduled_hours' => 2,
        'banner_stopped_hours' => 24,
        'live_poll_seconds' => 5,
        'banner_poll_seconds' => 15,
        // As portas que a saída descarta (na DigitalOcean, as de e-mail). A tarefa numa delas nem sai: a
        // cobertura diz "não testada, a saída bloqueia", nunca "fechada" (item 7 do Blue Team). O executor
        // recebe o mesmo valor (SECMON_SAIDA_PORTAS_BLOQUEADAS). Malformada, o portão recusa toda ação.
        'egress_blocked_ports' => env('PENTEST_EGRESS_BLOCKED_PORTS', '25,465,587'),
        // O termo e o aviso ao time, um arquivo cada, com o hash fixado em PentestTerms (os testes trocam a pasta).
        'terms_path' => resource_path('pentest/terms'),
        // A pasta trocada com o executor (no volume do app; a raiz é do root, e o instalador do servidor a cria) e
        // por quanto tempo o app guarda o que vem de lá: as respostas aplicadas e as recusadas, 7 dias; o registro
        // de atividade de cada pedido, 180 dias (a prova do que foi enviado, Q33). O runner nunca apaga nada disso.
        'executor_dir' => storage_path('app/pentest'),
        'executor_retention_days' => ['done' => 7, 'rejected' => 7, 'logs' => 180],
        // O catálogo (P11, `v1/manifest.json`) e o arquivo único do UA (`identity.json`): o mesmo que a imagem do
        // executor leva (contexto nomeado do build). Sem o manifesto, nenhum pedido ao executor é montado.
        'catalog_path' => resource_path('pentest/catalog'),
        // O pedido ao executor (contrato v1). O prazo de cada pedido: até 15 minutos, sem passar do fim da janela
        // menos 1 minuto nem do fim da validade. Os ritmos saem da intensidade aceita (PentestBudget, na regra).
        'request_minutes' => 15,
        'request_window_margin_seconds' => 60,
        // A leitura de cada resposta do site: 1 MiB nos testes (o executor lê só o começo) e no mapeamento (a página inteira, que
        // não sai do executor), 64 KiB no resto.
        'max_response_bytes' => ['testing' => 1048576, 'revalidation' => 1048576, 'mapping' => 1048576, 'default' => 65536],
        // O disjuntor do executor (D5): falhas seguidas, bloqueios do WAF (por IP e por sonda) e a espera máxima
        // que um Retry-After pode pedir.
        'breaker' => ['consecutive_failures' => 3, 'waf_ip_blocks' => 1, 'waf_probe_blocks' => 2, 'retry_after_cap_s' => 120],
        // O IP de saída que o termo promete (a VPS de saída, I3; vírgula) e o DNS reverso dele.
        'egress_ips' => explode(',', (string) env('PENTEST_EGRESS_IPS', '')),
        'egress_rdns' => env('PENTEST_EGRESS_RDNS', 'scanner.example.com'),
        // Nunca entram, nem os subdomínios deles: o próprio Security Monitor sempre, mais os da variável.
        'forbidden_domains' => array_values(array_unique(array_filter(array_map(fn ($name) => strtolower(trim($name)), array_merge(
            ['example.com'],
            explode(',', (string) env('PENTEST_FORBIDDEN_DOMAINS', 'example.com')),
        ))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Radar de domínios
    |--------------------------------------------------------------------------
    | Nomes parecidos com o domínio verificado do cliente. O DNS, o registro
    | (RDAP) e os certificados (crt.sh) são consultados daqui; as páginas são
    | abertas pelo navegador do docker/browser, que só sobe quando há pedido
    | na pasta abaixo e morre ao terminar. Nada disso faz request ao site do cliente.
    */
    'lookalikes' => [
        'enabled' => env('LOOKALIKES_ENABLED', true),
        // Trocada com o container do navegador: pedidos em inbox/, respostas em outbox/, prints em shots/.
        'browser_dir' => env('LOOKALIKES_BROWSER_DIR', storage_path('app/lookalike')),
        // Teto de variações por varredura: ~13 mil para um nome de 9 letras nas 100 terminações e ~26 mil
        // para um de 20. Só corta nomes acima de ~23 letras, e corta as terminações menos prováveis.
        'max_variants' => 30000,
        // O DNS é conferido em lotes de jobs na fila `lookalikes` (4 workers dedicados em produção):
        // cada lote tem o seu retry, e um que falha não perde os outros.
        'dns_batch_size' => 100,
        'dns_batch_tries' => 3,
        'dns_batch_backoff' => [30, 120],
        // Nomes que o DNS não respondeu (timeout, SERVFAIL) são perguntados de novo dentro do lote.
        'dns_retry_passes' => 2,
        'dns_retry_pause_ms' => 500,
        // Tempo máximo de um lote perguntando ao DNS: um nome mudo custa ~30 s por pergunta.
        'dns_batch_budget_seconds' => 90,
        // Passou desta fração sem resposta, o lote inteiro falha e tenta de novo mais tarde.
        'dns_unanswered_max' => 0.2,
        // Trava da varredura em lotes: sem fim em tantas horas, a próxima rodada do agendador libera.
        'scan_lock_hours' => 3,
        // Varredura completa de todo Radar ativo, todo dia neste horário (fuso de Brasília): variações que
        // alguém registrou desde a última, DNS dos achados e, dos que existem, as páginas que mudaram ou
        // ficaram sem print recente.
        'scan_at' => '01:00',
        'capture_every_days' => 7,
        'capture_batch' => 25,
        // "Verificar agora" do cliente.
        'manual_scan_hours' => 24,
        // Prints mais velhos que isso são apagados, menos os que marcaram mudança de nível.
        'keep_days' => 90,
        'free_variants' => 15,
        // Página em branco (ou atrás de desafio anti-robô) e domínio já denunciado:
        // o navegador volta todo dia para ver se mudou (ou se caiu).
        'daily_states' => ['blank', 'challenge'],
        // 3 páginas do site do cliente (a inicial e duas linkadas nela) nos 3 aparelhos,
        // para comparar com os achados. Exige o termo v3 no passo 5.
        'reference_pages' => 3,
        'reference_every_days' => 30,
        'reference_ua_suffix' => 'SecurityMonitorBot/1.0 (+https://example.com/sobre)',
        // Print parecido com uma página do cliente a partir disto conta como imitação visual.
        'looks_like_threshold' => 88,
        // Parecer da IA antes do aviso: só sai acima disto (como os alertas de notícia).
        'ai_review_enabled' => env('LOOKALIKES_AI_REVIEW', true),
        'ai_threshold' => 70,
        // Plano empresarial (E4a, DESENHO-5.3 §5.2): o Radar de empresa relê o DNS dos subdomínios ativos do domínio dele
        // em cada varredura completa e avisa a tomada (CNAME para destino que não resolve) e a mudança para fora da zona.
        'subdomains_enabled' => (bool) env('LOOKALIKES_SUBDOMAINS_ENABLED', false),
        'crtsh_url' => 'https://crt.sh/',
        'rdap_bootstrap_url' => 'https://data.iana.org/rdap/',
        'timeout' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoramento do GitHub
    |--------------------------------------------------------------------------
    | Uma vez por dia, a API oficial do GitHub procura em código e repositórios
    | públicos os nomes de chaves, endereços internos e repositórios privados
    | que o cliente informou na entrevista própria, e repositórios com a marca.
    | A busca em código exige token (qualquer token, sem escopo: só lê o que é
    | público); sem ele, só a busca de repositórios roda. Cada achado passa por
    | duas etapas de IA (classificação por nível e parecer de envio).
    */
    'github' => [
        'enabled' => env('GITHUB_MONITOR_ENABLED', true),
        'token' => env('GITHUB_SEARCH_TOKEN'),
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        'timeout' => 20,
        'scan_every_hours' => 24,
        // "Verificar agora" do cliente.
        'manual_scan_hours' => 24,
        // A busca em código aceita 10 por minuto; cada termo do cliente é uma busca.
        'max_code_queries' => 30,
        'max_repo_queries' => 20,
        'per_page' => 30,
        // A varredura espera o limite da API voltar até este tanto; passou disso, o resto fica para o dia seguinte.
        'max_wait_seconds' => 65,
        // Achados que sumiram da busca: confere se o arquivo ainda existe (os mais graves primeiro).
        'recheck_gone' => 20,
        // Texto "Adicional" da entrevista, que a IA interpreta.
        'extra_max' => 1000,
        'extra_daily_limit' => 5,
        // Etapa 1 (classificação) e etapa 2 (parecer de envio, só acima do corte).
        'ai_triage_enabled' => env('GITHUB_AI_TRIAGE', true),
        'ai_review_enabled' => env('GITHUB_AI_REVIEW', true),
        'ai_threshold' => 70,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exploits públicos (Exploit-DB)
    |--------------------------------------------------------------------------
    | Referência, não notícia: o índice oficial (o mesmo CSV que o searchsploit
    | usa) é baixado uma vez por dia para o Postgres, e cada caso com CVE é
    | cruzado com ele. A notícia continua vindo só dos feeds.
    */
    'exploits' => [
        'enabled' => env('EXPLOITS_ENABLED', true),
        'index_url' => 'https://gitlab.com/exploit-database/exploitdb/-/raw/main/files_exploits.csv',
        // Arquivo de cada exploit, lido só para a IA escrever a explicação.
        'raw_base' => 'https://gitlab.com/exploit-database/exploitdb/-/raw/main/',
        'page_base' => 'https://www.exploit-db.com/exploits/',
        'timeout' => 60,
        // O índice tem ~47 mil linhas. Bem menos que isso é download cortado:
        // a sincronização para antes de apagar qualquer coisa.
        'min_rows' => 30000,
        // Explicação pela IA (hermes): o código vai para o modelo, a página
        // mostra só a explicação e o link.
        'explain_enabled' => env('EXPLOITS_EXPLAIN', true),
        'max_code_bytes' => 512 * 1024,
        'prompt_chars' => 30000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Explicação da vulnerabilidade
    |--------------------------------------------------------------------------
    | Depois que a matéria fica pronta (abriu, ganhou informação nova ou saiu da
    | fusão), a IA (hermes) lê a matéria e os artigos e explica a falha e como
    | ela foi descoberta. Só para matéria de vulnerabilidade.
    */
    'vulnerability' => [
        'explain_enabled' => env('VULN_EXPLAIN', true),
        // Espera a matéria assentar: artigos da mesma coleta chegam juntos e
        // viram uma chamada só.
        'delay_minutes' => 15,
        // Artigos lidos (o começo da história e o mais recente) e o texto total.
        'max_articles' => 24,
        'prompt_chars' => 60000,
        'timeout' => 240,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fusão de matérias parecidas
    |--------------------------------------------------------------------------
    | A coleta só junta fontes por CVE e por título parecido, então a mesma
    | história contada de outro ângulo ("Cloudflare vê ataques" e "PHP marca
    | correção") vira matérias separadas. Duas vezes ao dia, as novas são comparadas
    | com as da janela pelo texto; a IA (hermes) confirma quais são a mesma
    | história e escreve uma matéria nova com tudo. As antigas são apagadas e
    | o link delas redireciona para a nova.
    */
    'merge' => [
        'enabled' => env('CASES_MERGE', true),
        // Matérias novas da execução: a fusão roda às 00:00 e às 12:00, e a janela
        // cobre as duas últimas rodadas e mais uma hora, para a matéria que
        // ainda estava na análise ou um atraso do agendador.
        'focus_hours' => 25,
        // Até onde elas são comparadas: a mesma janela do agrupamento da coleta.
        'window_days' => 14,
        // Cosseno TF-IDF de título, resumo e indicadores a partir do qual o par
        // (e, no grupo, a média dos pares) vai para a IA. CVE em comum soma o bônus.
        'min_similarity' => 0.22,
        'cve_bonus' => 0.10,
        // Matérias por grupo levado à IA (as mais centrais).
        'max_group' => 12,
        // Fontes lidas pela IA ao escrever a matéria nova.
        'max_sources' => 24,
        'timeout' => 240,
    ],

    'per_page' => 20,

    /*
    |--------------------------------------------------------------------------
    | Sitemap (/sitemap.xml)
    |--------------------------------------------------------------------------
    | As páginas fixas e as matérias publicadas, lidas do banco na hora e
    | guardadas no cache até uma matéria entrar, mudar ou sair do ar. O
    | protocolo aceita 50 mil endereços por arquivo; acima de `per_file`
    | matérias, o /sitemap.xml vira um índice de /sitemap-1.xml, /sitemap-2.xml...
    | `cache_minutes` é só a rede de segurança para o que muda o banco sem
    | passar pelo modelo (uma migration, um comando que apaga em lote).
    */
    'sitemap' => [
        'per_file' => 45000,
        'cache_minutes' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | IndexNow (aviso aos buscadores)
    |--------------------------------------------------------------------------
    | Bing, Yandex, Seznam, Naver e Yep recebem na hora o endereço da matéria
    | nova, da que volta ou sai do feed e das que a fusão junta (a busca do
    | ChatGPT usa o índice do Bing); o Google não usa o IndexNow e lê o sitemap.
    | A chave é pública por desenho: o arquivo public/<chave>.txt, com ela
    | dentro, prova que o site é nosso. Trocar a chave pede o arquivo novo.
    | O padrão liga só em produção; o admin desliga em Configurações.
    */
    'indexnow' => [
        'enabled' => (bool) env('INDEXNOW_ENABLED', env('APP_ENV') === 'production'),
        'key' => env('INDEXNOW_KEY', '57f49f47ca80099173078c336900f374'),
        'endpoint' => env('INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow'),
        'timeout' => 10,
        // O aviso espera a rodada da coleta terminar: as matérias dela vão juntas.
        'delay_seconds' => 60,
        // O mesmo endereço não vai de novo antes disso.
        'dedup_minutes' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoria de acessos (Admin → Acessos)
    |--------------------------------------------------------------------------
    | Cada página aberta no site, fora do admin: IP, país, navegador, a página e
    | o tempo aberta. O padrão vale até o admin ligar ou desligar na tela.
    */

    'monitoring' => [
        'enabled' => (bool) env('MONITORING_ENABLED', true),
        // Código do aparelho de quem navega sem login, só para segurança (DeviceSightings; /privacidade, seção 2).
        // Sem env, de propósito: mudar estes números é decisão de código, revista junto com a /privacidade.
        'devices' => [
            // Códigos distintos aceitos por IP por dia: quem inventa códigos para encher o banco para no 4º.
            'per_ip_per_day' => 3,
            // Raridade de um código pelos IPs distintos em que apareceu na janela: até `rare_ips`, raro
            // (liga os IPs); acima de `common_ips`, comum (iPhones iguais, headless em datacenter) e não liga.
            'window_days' => 30,
            'rare_ips' => 5,
            'common_ips' => 20,
        ],
    ],

    // O que o site recusa ou que tem cara de ataque (Admin → Acessos): o teto por minuto de um IP e o de todos.
    // Passado o teto, fica só a contagem do minuto (AccessEventRecorder).
    'access_events' => [
        'per_ip_per_minute' => (int) env('ACCESS_EVENTS_PER_IP_PER_MINUTE', 20),
        'per_minute' => (int) env('ACCESS_EVENTS_PER_MINUTE', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retenção (secmon:prune, todo dia de madrugada)
    |--------------------------------------------------------------------------
    | Dias que cada coisa fica no banco; 0 desliga a poda daquele item. Sessões
    | seguem session.lifetime (a /privacidade promete 4 h) e os prints do Radar
    | seguem lookalikes.keep_days (90 dias). Os outros prazos não são promessa:
    | são decisão do dono e mudam por variável de ambiente, sem tocar no código.
    */
    'retention' => [
        'crawl_runs_days' => (int) env('RETENTION_CRAWL_RUNS_DAYS', 180),
        // Tentativas de análise de cada artigo (Admin → Coletas): o mesmo prazo das execuções do crawler.
        'article_attempts_days' => (int) env('RETENTION_ARTICLE_ATTEMPTS_DAYS', 180),
        'failed_jobs_days' => (int) env('RETENTION_FAILED_JOBS_DAYS', 30),
        // O texto enviado (payload) sai antes da linha: some o conteúdo, fica o registro de que houve envio.
        'delivery_payload_days' => (int) env('RETENTION_DELIVERY_PAYLOAD_DAYS', 90),
        'delivery_days' => (int) env('RETENTION_DELIVERY_DAYS', 365),
        // Páginas abertas no site (Admin → Acessos): IP, país, navegador e página, o prazo dos registros de acesso.
        'page_views_days' => (int) env('RETENTION_PAGE_VIEWS_DAYS', 180),
        // Código do aparelho (só segurança): a /privacidade promete 6 meses, contados da última vez que foi visto.
        'device_sightings_days' => (int) env('RETENTION_DEVICE_SIGHTINGS_DAYS', 180),
        // O que o site recusou ou que pareceu ataque, e a contagem do que passou do teto: o mesmo prazo.
        'access_events_days' => (int) env('RETENTION_ACCESS_EVENTS_DAYS', 180),
        // Registro dos e-mails enviados (Admin → E-mails): para quem, o assunto e o resultado.
        'email_messages_days' => (int) env('RETENTION_EMAIL_MESSAGES_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | E-mail
    |--------------------------------------------------------------------------
    | O envio sai pelo servidor do próprio stack (docker/mail: Postfix + DKIM, só
    | de saída, alcançável só pela rede interna como mail:25). O mailer em si é o
    | config/mail.php (MAIL_MAILER=smtp, MAIL_HOST=mail, MAIL_PORT=25 em produção).
    | Daqui saem o ritmo, o log de entrega que o app lê e o que o Admin → E-mails
    | confere no DNS.
    */
    'mail' => [
        // Envios por minuto no servidor inteiro: a VPS aceita poucos pela porta 25.
        'per_minute' => (int) env('MAIL_PER_MINUTE', 5),
        // Log do Postfix e o registro DKIM gerado no boot (volume mail_log, só leitura).
        'log_path' => env('MAIL_LOG_PATH', '/var/log/secmon-mail/mail.log'),
        'dkim_path' => env('MAIL_DKIM_PATH', '/var/log/secmon-mail/dkim.txt'),
        'dkim_name_path' => env('MAIL_DKIM_NAME_PATH', '/var/log/secmon-mail/dkim-name.txt'),
        // Nome com que o servidor se apresenta (o PTR do IP): o SPF precisa ter o IP dele.
        'helo' => env('MAIL_HELO', 'mail.example.com'),
        // O IP público, para quando o nome acima resolve para endereço interno dentro do
        // container (o /etc/hosts do servidor aponta o próprio nome para 127.0.1.1).
        'public_ip' => env('MAIL_PUBLIC_IP', '203.0.113.10'),
        'domain' => env('MAIL_DOMAIN', 'example.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plano empresarial (empresas com várias contas e setores)
    |--------------------------------------------------------------------------
    | Tudo atrás da chave global, desligada por padrão. Com ela ligada, cada
    | empresa ainda precisa da própria chave (companies.enterprise_enabled_at,
    | a empresa piloto primeiro) e de não estar suspensa. Os limites são os
    | padrões; o admin do Security Monitor ajusta por empresa (as colunas limit_* de
    | companies; nulo = o padrão daqui).
    */
    'enterprise' => [
        'enabled' => (bool) env('ENTERPRISE_ENABLED', false),
        'limits' => [
            // As contas incluem a admin (R1).
            'accounts' => 10,
            // Domínios comprovados, somando todos os setores (R5).
            'domains' => 15,
            // Subdomínios por domínio comprovado; não contam nos domínios (R6).
            'subdomains' => 20,
            'sectors' => 10,
            // Os dos outros serviços (§4.8, D1).
            'github_monitors' => 5,
            'alert_profiles' => 20,
            'sector_channels' => 5,
            'general_channels' => 5,
            'webhooks' => 10,
            'radar_seeds' => 300,
            // Convites (convidar e reenviar) por empresa a cada 24 horas: cada um é um e-mail a um terceiro.
            'daily_invites' => 50,
        ],
    ],

    // Contato público: pedidos de privacidade (LGPD), dúvidas sobre os termos
    // e relatos de segurança. O example.com recebe e-mail pelo Email Routing da Cloudflare.
    'contact_email' => env('SECMON_CONTACT_EMAIL', 'contato@example.com'),

    // O /.well-known/security.txt (RFC 9116). Falha de segurança no próprio
    // Security Monitor vai para `security`; tráfego vindo do Security Monitor (o navegador do Radar,
    // as verificações de segurança e, quando ligarem, os testes ativos), para
    // `abuse`. Os dois chegam pelo Email Routing da Cloudflare.
    'security_txt' => [
        'security' => env('SECMON_SECURITY_EMAIL', 'security@example.com'),
        'abuse' => env('SECMON_ABUSE_EMAIL', 'abuse@example.com'),
        'languages' => 'pt, en',
    ],
];
