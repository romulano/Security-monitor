# Security Monitor

Agregador de inteligência de ameaças: coleta **direto dos feeds das fontes**,
transforma cada notícia numa matéria analítica com IA, agrupa as fontes que
falam do mesmo incidente num único caso e expõe tudo com busca em sintaxe
Elasticsearch, facetas e dashboard.

> A coleta vem exclusivamente dos feeds RSS/Atom das próprias fontes. A
> aplicação não consome nenhuma API de notícias de terceiros — o teste
> `NoExternalNewsApiTest` mantém a lista dos arquivos que podem acessar a rede
> e quebra o build se aparecer um novo fora dela. O índice do Exploit-DB está
> nessa lista como **referência**, não como notícia: ele só diz se um CVE já
> tem exploit público.

Sobe inteiro em Docker, na **porta 1515**.

```bash
make setup     # .env, dependências, assets, containers, banco e índice
# → http://localhost:1515              feed público
# → http://localhost:1515/admin/login  admin (admin@example.com / secmon)
# → http://localhost:1515/login        usuário comum (cadastro aberto em /cadastro)
```

---

## O que ele faz

| Recurso | Como |
|---|---|
| Feed de casos com criticidade, fontes e nº de atualizações | ordenado por `latest_at` |
| Busca `AND / OR / NOT`, aspas, curingas, `campo:valor` | Elasticsearch `query_string` |
| Facetas de criticidade, setores e atores | agregações do Elasticsearch, clicáveis |
| Matéria com resumo markdown, linha do tempo por fonte e indicadores | incluindo o "o que há de novo" por atualização |
| Dashboard (casos, críticos, atividade/dia, top atores/CVEs/malwares/países) | agregações por janela de tempo |
| Coleta contínua de feeds RSS/Atom | 69 fontes, coletadas direto na origem |
| Agrupamento de fontes repetidas no mesmo caso | dedup por CVE + similaridade de título |
| Fusão noturna das matérias da mesma história | à meia-noite, texto parecido + confirmação da IA → matéria nova; link antigo redireciona |
| Exploit público na matéria, com explicação da IA | índice do Exploit-DB cruzado pelo CVE, revisto todo dia |
| API de leitura `/api/news[...]` | JSON, formato fixo campo a campo |

A UI é densa e focada em leitura, sem hero, com filtros sempre visíveis e atalho
`/` para a busca. A **área administrativa** liga/desliga bots, gere fontes e
troca o driver de IA sem tocar em container.

---

## Usuários

Há dois logins sobre a mesma tabela `users`, separados pela coluna `is_admin`:

| Rota | Quem entra | O que pode |
|---|---|---|
| `/admin/login` | só admin | tudo em `/admin` |
| `/login` | só usuário comum | `/conta`: trocar nome, e-mail e senha (e-mail e senha pedem a senha atual) |

Credencial da área errada recebe a mesma resposta de senha errada, para não
revelar que a conta existe.

O cadastro do usuário comum é aberto, em `/cadastro`: nome, e-mail e a senha
que quiser (mínimo de 8 caracteres, digitada duas vezes), com o captcha
(Turnstile) e o e-mail confirmado por link antes de usar os alertas. Não há mais
convite: o link antigo `/cadastro?convite=...` abre o cadastro normalmente e o
parâmetro é ignorado. A tabela `invites` ficou no banco só como histórico de
quem entrou por convite. O admin vê as contas em **Admin → Usuários**
(`/admin/usuarios`; o antigo `/admin/convites` redireciona para lá).

A senha é a que a pessoa digitou no cadastro; ela troca em `/conta`. Só quem se
cadastra com o GitHub recebe uma senha gerada, mostrada uma vez em `/conta`, para
poder entrar também pelo e-mail.

**Trocar o e-mail pede a senha atual** (ou a reautenticação recente, abaixo); trocar
só o nome não pede. Sem isso, quem roubasse a sessão trocaria o e-mail de entrada
e, por ele, ligaria o próprio GitHub à conta, porque o primeiro login com o GitHub
liga a conta pelo e-mail verificado. **Trocar a senha** encerra as outras sessões e
o "continuar conectado" dos outros aparelhos, como na troca pelo admin; a sessão de
quem trocou continua.

**Reautenticação recente.** Antes de uma ação sensível, a pessoa confirma que é
ela, além de estar logada: quem tem só a sessão (um cookie roubado) não passa. A
confirmação vale 10 minutos, na mesma sessão e para o mesmo usuário, e o id da
sessão muda ao confirmar, então quem tiver uma cópia do cookie antigo fica sem
ela. Na rota, é o middleware `reauth` (`App\Http\Middleware\EnsureRecentReauth`):
o formulário da ação pode mandar a senha junto, no campo `reauth_password`, e o
método usado (`password`, `github` ou `code`) sai de `Reauthentication::method()`
para o registro da ação. A senha conferida com a sessão aberta (na ação sensível,
na troca de e-mail e na troca de senha) tem um limite só: 5 tentativas a cada 5
minutos por conta. Ver uma tela nunca pede confirmação, e parar algo também não.

Quem não sabe a senha (a senha inicial de quem se cadastrou pelo GitHub) confirma
de outro jeito, na caixa "Confirme que é você"
(`resources/views/account/partials/reauth.blade.php`):

- **pelo GitHub**, só se a conta já tem o GitHub ligado, comparado pelo id. A
  reautenticação nunca liga nada pelo e-mail (o login liga, na primeira vez), e o
  admin, que entra só por senha, não confirma pelo GitHub. A volta é
  `/login/github/callback/reautenticar`, subcaminho do callback do OAuth App.
- **por um código de 6 dígitos** num app conectado, só numa conversa privada do
  Telegram ou mensagem direta do Discord (num grupo, outras pessoas leriam) e só
  numa que já estava conectada, no mesmo destino, quando a pessoa entrou com a
  senha ou o GitHub (o login guarda a lista; entrar pelo "continuar conectado"
  não conta). Assim, quem só tem a sessão não conecta o próprio Telegram para
  receber o código. Vale 10 minutos, uma vez só, 5 tentativas, só na sessão que
  pediu, e 3 códigos a cada 10 minutos por conta. O cache guarda só o HMAC, e o
  envio sai na hora (sem fila) e fica no histórico do app como `code`, com pontos
  no lugar do código; o admin não reenvia código.

O **diário de bordo** da `/sobre` fica no banco (`log_entries`) e o admin cria,
edita e remove os registros em **Admin → Diário de bordo**. A página mostra os 3
mais recentes (ou todos, se forem até 4) e guarda os mais antigos atrás de "ver mais".

**E-mail.** O envio sai pelo servidor do próprio stack, o serviço `mail`
(`docker/mail`): Postfix só de saída com assinatura DKIM (OpenDKIM). Ele não tem
porta publicada, fica só na rede interna (o app fala com `mail:25`, sem usuário e
senha), aceita só os containers dessa rede (o gateway, que é o host, é recusado) e
só remetente `@example.com`. Não recebe e-mail: o recebimento segue no Email Routing
da Cloudflare. Apresenta-se com o nome reverso do IP do servidor
(`MAIL_HELO=mail.example.com`, que volta para o mesmo IP). A chave DKIM nasce
no primeiro boot, no volume `mail_dkim`, e o registro a cadastrar fica no volume
`mail_log`, junto do log de entrega.

Todo e-mail passa pelo `App\Services\Mail\Outbox`: uma linha em `email_messages` e
o job `SendEmail` (fila `notifications`, no máximo `MAIL_PER_MINUTE` por minuto,
porque a VPS aceita poucos envios pela porta 25). O job anota o id da fila do Postfix
("queued as …"), e o `secmon:mail-status` (a cada minuto) lê o log de entrega e marca
cada mensagem como entregue, adiada ou devolvida, com a resposta do servidor do
destinatário. **Admin → E-mails** mostra se o envio está de pé (mailer, servidor,
DKIM, SPF e DMARC publicados, leitor do log), o que saiu, o que deu erro e um botão
de teste. Em produção: `MAIL_MAILER=smtp`, `MAIL_HOST=mail`, `MAIL_PORT=25`, o SPF
com `ip4:` do servidor e o TXT `secmon1._domainkey`. O teste do container
(`docker/mail/test/run.sh`, job "Servidor de envio" do CI) confere a assinatura com
a chave publicada, a recusa de remetente de fora e do host, e o log de entrega.

---

## Arquitetura

```
                 ┌───────── nginx :1515 ──────────┐
                 │                                │
   navegador ───▶│  php-fpm (app)  ◀── worker     │   scheduler (a cada hora)
                 │        │             (fila)    │        │
                 └────────┼─────────────┬─────────┘        │
                          │             │                  │
                   PostgreSQL      Elasticsearch      secmon:crawl
                (fonte de verdade)  (busca/facetas)
```

- **app / worker / scheduler** — mesma imagem PHP 8.4, papéis diferentes.
- **PostgreSQL** guarda fontes, artigos, casos, execuções e configurações.
- **Elasticsearch** responde feed, facetas e dashboard. É reconstruível a
  qualquer momento a partir do Postgres (`make reindex`).

### Camada HTTP

Toda requisição faz o mesmo caminho:

```
rota → middleware → controller (__invoke) → service → modelos, jobs, clientes de API
```

- **Um controller por rota**, só com `__invoke`, em
  `app/Http/Controllers/<Área>/<Verbo><Coisa>Controller.php` (ex.:
  `Admin/Sources/StoreSourceController`). Ele recebe o form request, chama o
  service e escolhe a resposta: view, redirect com a mensagem, JSON ou arquivo.
  Não consulta o banco, não despacha job e não fala com API nenhuma.
- **Um form request por controller** que lê algo da requisição (campos, query,
  sessão, usuário), com o mesmo nome:
  `app/Http/Requests/Admin/Sources/StoreSourceRequest`. Nele ficam as regras e
  mensagens de validação, o `authorize()` (o registro é de quem pediu? A base
  `App\Http\Requests\Request` responde 404 na recusa, para não revelar que ele
  existe) e acessores tipados (`filters()`, `channel()`...). Regras que várias
  telas repetem ficam numa base abstrata na mesma pasta.
- **O service faz o trabalho** (`app/Services/<Área>/`): consulta, grava,
  despacha os jobs e chama os clientes (Slack, Discord, Telegram...). Quando
  uma regra do negócio recusa o pedido, ele lança
  `App\Exceptions\ActionRefused('mensagem')`, que volta à página anterior com
  o aviso em `error`.

### Chamadas à IA (hermes)

Toda etapa que usa IA (enriquecimento, parecer dos alertas, fusão, explicações,
Radar, GitHub...) fala com o hermes por **um cliente só**,
`App\Services\Hermes\Hermes`: `chat()` devolve o texto da resposta, `json()`
já devolve o objeto, e `url()`, `configured()` e `http()` cuidam do endereço e
do token. O serviço da etapa monta o prompt e interpreta a resposta (o seu
`parse()`); nunca monta a requisição. Uma etapa nova que precise de IA chama
`Hermes::chat()`, e o `tests/Unit/NoExternalNewsApiTest` falha se um arquivo
fora da lista dele abrir conexão por conta própria.

### Pipeline de uma notícia

```
feed RSS/Atom
   └─ FeedReader ......... lê o feed, normaliza datas e limpa parâmetros de rastreio
        └─ dedup por URL e por hash de conteúdo
             └─ ArticleExtractor ... entra no site da matéria e extrai o texto principal
                  └─ CaseResolver .. caso novo ou mais uma fonte de um caso existente?
                       └─ Enricher .. IA gera título, resumo markdown, criticidade e indicadores
                            └─ CaseWriter ... cria o caso, anexa a atualização ou marca repetição
                                 └─ CaseIndex ... indexa no Elasticsearch
```

A extração do texto existe porque quase nenhuma fonte publica o conteúdo
completo no RSS — só um resumo. Sem entrar na matéria, a análise ficaria pobre.

---

## Bots

| Bot | O que faz | Quando roda |
|---|---|---|
| **Crawler** | lê os feeds, entra nas matérias, dedup, enfileira a análise | de hora em hora (scheduler); cada fonte respeita o seu `interval_minutes` |
| **Análise (IA)** | resumo, criticidade e ~18 grupos de indicadores | na fila, logo após a coleta |
| **Reprocessamento** | reenfileira artigos que travaram na análise | de hora em hora, aos 30 min |

Cada fonte tem o seu próprio intervalo (`interval_minutes`, padrão 60), então o
agendamento horário só chama quem já está no horário — feeds que publicam pouco
podem ser afrouxados sem mexer no scheduler.

Crawler e análise são ligáveis/desligáveis em **Admin → Configurações** (a
tabela `settings` tem precedência sobre o `.env`, sem precisar recriar
container).

**Admin → Coletas** é o painel operacional: mostra em ordem de chegada tudo que
os bots publicaram, com fonte, horário, se virou matéria nova / atualização /
repetição, quantos caracteres foram extraídos e a saúde de cada fonte. Dá para
filtrar por fonte, status e janela de tempo.

### Drivers de IA

`ENRICHER_DRIVER` = `claude` | `openai` | `hermes` | `heuristic`

- **claude** — SDK oficial `anthropic-ai/sdk`, Messages API com
  `output_config.format` (JSON Schema), o que garante a estrutura da resposta.
  Modelo padrão `claude-opus-5`, `effort: low` (a tarefa é extração estruturada).
- **openai** — qualquer endpoint compatível com `/chat/completions`
  (OpenAI, Groq, LM Studio, Ollama) via `OPENAI_BASE_URL`, usando
  `response_format: json_schema`.
- **hermes** — o Claude Code da própria máquina, pela ponte local
  `~/job/hermes` (`/v1/chat/completions`). Sem chave de API: usa o login do
  `claude` do computador. O hermes escuta no gateway da rede do compose
  (`172.31.88.1`, sub-rede fixa) e o schema vai no prompt, porque o CLI não
  aceita `response_format`. Endereço, modelo e esforço se ajustam em
  `/admin/config`, que também mostra se a ponte está no ar.
- **heuristic** — sem IA e sem custo: regex de CVE/IP/hash/T-code + dicionários
  de atores, malwares e setores. **É também o fallback automático**: se o driver
  escolhido estiver sem chave ou o provedor cair, a notícia é salva assim mesmo
  e o motivo fica registrado no artigo.

As chaves (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `HERMES_TOKEN`) ficam só no `.env` —
deliberadamente não editáveis pela web.

> Sem chave configurada, a coleta roda com o driver heurístico. Depois de
> colocar a chave, `make reanalyze` refaz a análise do que já foi coletado
> reaproveitando o conteúdo baixado — nada é buscado de novo na internet.

### Como as repetições são filtradas

Um artigo entra num caso existente quando compartilha CVE **ou** quando os
tokens significativos do título passam do limiar de Jaccard
(`crawler.dedup_threshold`, padrão `0.62`, ajustável no admin), dentro de uma
janela de 14 dias. Esse agrupamento é só por título e CVE, então a IA, que lê
os dois textos, dá a palavra final:

- **outro assunto** (`same_case: false`) → o agrupamento é desfeito e o artigo
  vira **matéria própria**. É o caso de séries com título fixo, como o resumo
  semanal "The Good, the Bad and the Ugly… Week 39/40", em que o número da
  edição some na comparação de títulos;
- **mesmo assunto, com informação nova** → vira uma atualização na linha do
  tempo, com o campo "o que mudou". O topo da matéria passa a mostrar a análise
  mais recente, e a anterior continua na linha do tempo ("ler a análise desta
  fonte");
- **mesmo assunto, sem novidade** → o artigo é marcado como `no_update` e **não
  polui o feed**, mas continua contabilizado como repetição na matéria.

O driver heurístico não tem como julgar o assunto e sempre aceita o
agrupamento.

### Fusão noturna de matérias parecidas

O agrupamento da coleta só olha título e CVE, então a mesma história contada
de outro ângulo escapa: "Cloudflare vê ataques contra duas falhas do PHP" e
"PHP marca correção de emergência do php2shell" viram matérias separadas. Toda
noite, às **00:00 (Brasília)**, o `secmon:cases-merge` junta essas matérias:

```
matérias novas (26 h) × janela de 14 dias
   └─ SimilarCases ... cosseno TF-IDF de título, resumo e indicadores (CVE em comum soma bônus)
        └─ ligação média: cada matéria precisa parecer com o grupo todo, não só com uma
             └─ MergeSimilarCases (fila merge) ─▶ MergeEditor (hermes)
                  ├─ confirma quais são a mesma história (na dúvida, separa)
                  └─ escreve a matéria nova com todas as fontes, em inglês e pt-BR
                       └─ CaseMerger ... cria a nova, move fontes, alertas e exploits,
                                         apaga as antigas e grava o redirecionamento
```

- **Matéria nova**: título e resumo reescritos pela IA a partir da análise de
  cada fonte, com linha do tempo, detalhes técnicos, remediação e relatos
  divergentes, cada afirmação com a fonte entre parênteses. Criticidade é a
  mais alta do grupo e os indicadores são a união. A matéria mostra o aviso
  "Matéria consolidada" com as fontes de todas.
- **Matérias antigas são apagadas** e o uuid de cada uma fica em `case_merges`:
  `/materia/<uuid antigo>` e `/api/news/case/<uuid antigo>` respondem **301**
  para a nova (inclusive em cadeia, quando a nova é fundida de novo). Os links
  dos alertas já enviados continuam funcionando. O `snapshot` guarda como cada
  matéria era, com os artigos, alertas e exploits que saíram dela.
- **Alertas**: os registros (e os votos dos clientes) passam para a nova, então
  o juiz enxerga tudo como um caso só. A fusão não gera alerta.
- **Coleta**: a fonte nova de uma matéria apagada acha a nova pelo título e
  pelos CVEs antigos. Matéria consolidada que recebe fonte com informação nova é
  reescrita pela IA na noite seguinte, no mesmo link.
- **Fila `merge`**: o worker de análise só pega a fusão com a `enrich` vazia,
  então a notícia que chega à meia-noite não espera os grupos da noite.
- **Só com a IA**: sem o hermes (ou com `CASES_MERGE=false`) nada é juntado. Grupo
  que a IA recusou inteiro não volta a ela até mudar. Limiares em
  `config/secmon.php` (`merge`), calibrados com as 946 matérias de produção de
  21/10: as 4 do php2shell saem juntas e sem o Rank Math, que só cita o CVE.

```bash
docker compose exec app php artisan secmon:cases-merge --candidates   # só os grupos parecidos, sem IA
docker compose exec app php artisan secmon:cases-merge --dry-run      # o que a IA juntaria, sem gravar
docker compose exec app php artisan secmon:cases-merge --since=3d     # janela maior que a da noite
docker compose exec app php artisan secmon:cases-merge --sync --case=<uuid> --case=<uuid>   # grupo escolhido à mão
```

---

## Alertas personalizados

O cliente responde uma entrevista de 7 passos em **Alertas** (`/alertas`) e
passa a receber no Slack só o que afeta a stack dele.

```
entrevista ─▶ perfil ─▶ query ─▶ percolator (secmon_profiles)
crawler + IA ─▶ CaseWriter ─▶ MatchCaseToProfiles (fila alerts) ─▶ MatchEngine
   ─▶ alert_matches ─▶ AlertRouter ─▶ SendSlackMessage (fila notifications)
scheduler ─▶ secmon:alerts-digest (resumo diário) · secmon:alerts-flush (segurados)
```

- **Entrevista**: empresa e marcas, linguagens e frameworks, infraestrutura,
  rede, site e plataformas (WordPress, lojas, criadores como o Lovable, sem
  depender de marcar linguagem), ferramentas, domínio, nível mínimo e tipos de
  ameaça. As perguntas e o catálogo de tecnologias (termos de busca + regras de
  detecção) ficam no banco e são editáveis em **Admin → Alertas**; item que a
  descoberta cadastrou sozinha não vira opção na entrevista. No fim, uma prévia roda o
  perfil contra os casos reais dos últimos 30 dias.
- **Matching**: cada caso novo (ou atualização com informação nova) é
  percolado no Elasticsearch para achar os perfis candidatos; o `MatchEngine`
  decide e grava os motivos ("Fortinet · produto afetado", "cita a sua
  empresa"). Pesos: marca 100 (ignora nível e tipos), produto/fornecedor da
  stack 60, termo da stack só no texto 40, setor 40, CVE +20, país +10.
  Tecnologias amplas (Windows, Python, GitHub…) são `strict` no catálogo: só
  contam como produto afetado — sem isso, quase metade dos alertas de um perfil
  típico vinha de menção solta no texto. Repetição (`no_update`) nunca alerta.
- **Slack**: Incoming Webhook colado ou "Adicionar ao Slack" (OAuth v2, escopo
  `incoming-webhook`, só com `SLACK_CLIENT_ID`/`SLACK_CLIENT_SECRET` no
  `.env`). Na hora e/ou resumo diário (07:00 no fuso do cliente, uma vez por
  dia, até 4 h de tolerância se o worker esteve fora). Horário silencioso
  segura o que não é critical; passou de 10 mensagens/hora, o resto vai
  agrupado. Webhook só aceita `https://hooks.slack.com/services/…` (Incoming
  Webhook, Block Kit) ou `https://hooks.slack.com/triggers/…` (fluxo de
  trabalho do Slack: recebe uma variável de texto só, `notificacao`, com a
  notificação inteira — texto, links de "relevante" e "não é relevante" e, no
  Radar, o link do print do site —, para mandar na DM de alguém) e fica criptografado; 404/410 do Slack marca o canal como "precisa reconectar".
- **Descoberta pelo domínio**: só com aceite **e** prova de posse (registro
  TXT `secmon-verify=…`). É passiva: DNS (MX, SPF, TXT, NS, CNAME do www), um
  GET na página inicial, lida inteira (teto de 10 MB), e um GET num endereço
  que não existe (`/secmon-check-…`): a home costuma sair do cache da CDN ou de
  um front separado, e a página de erro vem do servidor de aplicação (um
  Laravel por trás de um front Nuxt em cache, por exemplo). E o blog, só se a
  home linkar para ele (`/blog/` ou `blog.<domínio>`; nada de tentar às cegas),
  que muitas vezes é outro sistema (um WordPress ao lado do app). Esses acessos extras só
  rodam com aceite do termo v2 em diante; quem aceitou o v1 vê um aviso para
  renovar. Página barrada por desafio anti-robô (Cloudflare "Just a moment…")
  não é contornada: o log registra, a home de alertas mostra "Não conseguimos
  ler tudo" e, se o site está atrás da Cloudflare, o passo 5 traz o passo a
  passo da regra Skip (aberto quando algo foi barrado, recolhido como
  recomendação quando não), com a expressão pronta: só o User-Agent
  `SecurityMonitorBot` e só os caminhos que a descoberta lê; com
  `ALERTS_DISCOVERY_EGRESS_IPS`, exige também o IP de saída do Security Monitor. Depois
  de liberar, o cliente relê só as páginas barradas (1 vez por dia).
  Tecnologia achada revela a de baixo ("pertence a" no catálogo): Laravel →
  PHP, Ubuntu → Linux, com evidência "deduzido de …". O IP é validado antes
  de cada request e de cada redirect (bloqueia rede privada, loopback, metadata
  da nuvem, CGNAT, IPv6 local, o IPv6 que leva um IPv4 dentro — 6to4, Teredo,
  NAT64 local — e todo IPv6 fora do espaço global 2000::/3) e fixado no cURL
  contra DNS rebinding; até 3 redirects, portas 80/443. Lê cabeçalhos,
  cookies, `<meta generator>`, scripts de terceiros e plugins/temas WordPress.
  O que for reconhecido entra direto no perfil (origem "domínio"), sem revisão;
  o cliente pode remover, e o que ele remove não volta. Sinal que **nenhuma
  regra do catálogo conhece** (um MX, um include de SPF, um script de
  terceiro…) é cadastrado sozinho no catálogo (`origin = discovery`, com a
  regra que o achou) e entra no perfil para monitoria: nasce `strict`, então
  só alerta quando uma notícia o listar como produto afetado. O admin revisa
  nome e termos em Catálogo → "descoberta automática".
- **Carga no site do cliente**: no máximo **1 varredura a cada 30 dias** por
  domínio, venha de onde vier (agendador, verificação do TXT, "Rodar agora" do
  admin, fila); a regra fica no `DiscoveryService` e conta a partir da última
  varredura que de fato pediu algo ao site (`last_scan_at`). O admin pode
  espaçar mais, nunca menos que 30 dias; só o operador força, no terminal
  (`secmon:alerts-discover <domínio> --force`). Cada varredura tem teto de
  **5 requisições** (`discovery.max_requests`, contando redirects) e nunca pede
  a mesma URL duas vezes; a página de erro é 1 requisição (redirect não é
  seguido); HTTP só se o HTTPS não responder. Renovar o aceite não dispara
  leitura. O cliente só pode reler as páginas barradas por desafio anti-robô,
  1 vez por dia, sem reiniciar o mês. Duas pessoas da mesma empresa com o
  mesmo domínio: a segunda reaproveita a varredura da primeira, sem requisição.
  Uma trava por domínio impede duas leituras ao mesmo tempo.

```bash
make alerts-match SINCE=7d   # reavalia casos recentes (registra sem notificar)
make alerts-digest           # manda agora o resumo de hoje a quem não recebeu
make alerts-recompile        # refaz as queries (depois de es-setup --fresh)
make alerts-probe HOST=x.com # diagnóstico da descoberta, sem gravar nada
```

O worker precisa ouvir as filas `alerts`, `notifications` e `discovery`
(o `docker-compose.yml` já está assim).

---

## Monitoramento do GitHub

Frente de "ameaças à marca", ao lado do Radar de domínios, com **entrevista
própria** em `/alertas/github` (separada da de alertas): organizações e
repositórios oficiais, repositórios privados, **nomes** de chaves (nunca o
valor: item com cara de valor é descartado antes de gravar), prefixos de token,
endereços internos, domínios de e-mail, marca, o que vigiar, um texto
"Adicional" e o nível mínimo (Somente Critical / High e acima / Medium e acima /
Todos).

- **Coleta**: uma vez por dia por cliente (`secmon:github-scan --due` a cada 15
  min; vence 24 h depois da anterior), pela **API oficial do GitHub** (busca em
  código exige `GITHUB_SEARCH_TOKEN`; até 30 buscas em código e 20 de
  repositórios por varredura, esperando o limite de 10/min). Fila `github`.
- **Achado**: um por arquivo ou repositório; o termo exato é conferido no trecho
  (a busca ignora pontuação). O `SecretMasker` mascara o valor de todo segredo
  antes de gravar, mostrar, mandar à IA ou ao app; ficam tamanho, entropia e se
  é referência ou exemplo. O que sumiu da busca é conferido na API e vira
  "removido do GitHub".
- **Etapa 1, classificação** (`GithubTriage`, prompt `github.triage`): nível
  critical/high/medium/low ou `none` (descartado) e a certeza de 0 a 100
  (abaixo de 40 é `none`). Abaixo do nível do cliente fica "abaixo do seu nível".
- **Etapa 2, parecer de envio** (`GithubJudge`, prompt `github.judge`): só para o
  que é achado no nível do cliente; aprende com as avaliações (as mesmas dos
  alertas: relevante, não relevante, "já resolvido" e o motivo) e com o que já
  saiu em 7 dias. Sai acima do corte (`github.ai_threshold`, 70, em Alertas ›
  Configurações); abaixo fica "segurado pela IA".
- **Texto "Adicional"** (`ExtraInterpreter`, prompt `github.extra`): só vai à IA
  se houver texto, se mudou e se não tem cara de instrução; só vale valor que
  está escrito no texto; no máximo 5 leituras por dia.
- Sem IA, a etapa 1 usa o nível pelas regras e a etapa 2 deixa sair. O tipo
  `github` entra em Notificadores (o que cada app recebe), no reenvio e no resumo diário (seção GitHub).

```bash
docker compose exec app php artisan secmon:github-scan cliente@empresa.com --sync   # varre agora e mostra o registro
```

---

## Segurança dos sites (Admin → Segurança)

O admin cadastra os sites dele que estão no ar e o Security Monitor os vigia, de forma recorrente
(a cada 6 h por site, em fila própria), avisando o que **piorou** desde a verificação anterior.
É **só leitura**: GET, consulta de DNS e um handshake TLS, nunca em endereço interno.

| Área | O que confere |
|---|---|
| Certificado e TLS | cadeia confiável, validade (aviso em 14 dias, crítico em 7), TLS 1.2/1.3 |
| HTTPS e cabeçalhos | HTTP → HTTPS, HSTS, CSP, X-Frame-Options/frame-ancestors, nosniff, Referrer-Policy, Permissions-Policy, COOP, versão no `Server`, `X-Powered-By` |
| Cookies e conteúdo | `Secure`, `HttpOnly` e `SameSite` de cada cookie da página inicial (só nomes e flags), conteúdo misto |
| Arquivos expostos | `/.env`, `/.git/HEAD`, log do Laravel, `phpinfo`, `server-status`, `.DS_Store`, dump e backup, **só com posse comprovada** |
| Domínio e e-mail | SPF, DMARC, `security.txt`, vencimento do domínio (RDAP) |

Nota = 100 menos os pontos perdidos (o "crítico" perde o peso inteiro, o "aviso" metade); o estado do
site é o pior de todos. Cada problema vira um *finding* (aberto/resolvido) e cada mudança de estado, um
evento (apareceu, piorou, resolvido, voltou): é daí que vem "o que piorou". O cartão do painel do admin
fica **vermelho também quando a varredura para** (mais de 48 h sem rodar).

**Posse.** Declarar "sou o responsável" no cadastro não basta (sessão roubada cadastraria o site de um
terceiro). Sem prova, só rodam as verificações passivas. A prova é o host resolver **só** para os
endereços do servidor do Security Monitor (`SECURITY_OWN_IPS`) ou um registro **TXT** `secmon-verify=<token>` no host ou
no domínio principal (o token aparece na tela do site; é o caso de quem está atrás da Cloudflare).

**Travas** (reaproveitam as da descoberta de domínios): só nome DNS no cadastro (nada de IP, porta,
`localhost` ou nome de container; `SiteHost`), todo IP resolvido passa pelo `IpGuard`, a conexão vai para o
IP já validado, só portas 80 e 443, no máximo 3 redirecionamentos conferidos um a um, teto de 25
requisições, 8 s de espera e 256 KB (4 KB nos caminhos sensíveis) por varredura. "Verificar agora": no
máximo uma vez por hora por site. **Nunca se guarda** o corpo das páginas, valor de cookie ou o certificado
inteiro; guarda-se estado, um valor curto (até 120 caracteres) e a data. Varreduras e eventos ficam 365
dias (`secmon.security.retention_days`).

```bash
docker compose exec app php artisan secmon:security-scan exemplo.com.br --sync   # varre agora e mostra a tabela
docker compose exec app php artisan secmon:security-scan --due                   # o que o agendador faz a cada 10 min
```

O worker é o programa `fila-seguranca` do supervisor (fila `security`, 1 processo): rede lenta de um
site não atrasa alerta nem análise.

---

## Testes de segurança (teste ativo, em construção)

O teste de segurança **ativo**, só no domínio que o cliente comprovou e autorizou, entra por partes (a
série de PRs da stack de testes). O que já existe:

- **Desligado por padrão.** Sem `PENTEST_ALLOWED=true` no ambiente, a área do cliente não existe (404) e
  nada da stack roda; o admin não liga isso. Na rota, é o middleware `pentest.available`.
- **O admin libera, conta por conta.** Com `PENTEST_ALLOWED=true`, toda conta vê a entrada (o cartão da
  Integração e o `/alertas/testes`), mas só usa a conta que o admin liberou em Admin → Usuários
  (`users.pentest_access_granted_at`). Sem a liberação, a página diz para pedir ao admin e tem o botão
  "Pedir liberação" (um pedido por conta); os passos e os outros POSTs voltam a ela sem executar nada
  (middleware `pentest.access`). Retirar a liberação para as execuções ativas da conta e não deixa
  autorizar nem iniciar; ver o histórico, revogar e excluir continuam valendo. O portão também confere
  (A5c). A conta de admin usa a área sempre, para os domínios dela, e a 1ª execução dela não espera a
  aprovação (`run.approval_skipped` na auditoria), porque ninguém aprova a própria execução.
- **A posse é a do Radar.** O domínio inscrito ganha a própria cópia da linha de `monitored_domains`
  (dona: `pentest_domains`), com o mesmo token e a data da prova quando vem de um domínio já comprovado
  no Radar ou em Notícias. O aceite passivo daqueles termos não vem junto: ele nunca vale para teste
  ativo. Excluir o Radar não leva a stack, e vice-versa. São até 3 domínios por conta; o `example.com` e os
  de `PENTEST_FORBIDDEN_DOMAINS` (com os subdomínios) nunca entram.
- **A posse é reconferida.** `DiscoveryService::recheck()` usa o `txtOrNull()` do DNS e separa o TXT no ar,
  o TXT que sumiu e o DNS que não respondeu (que não prova nada: só pausa). O resultado fica em
  `pentest_domains.ownership_status`, e a conferência a cada 10 minutos cobre os domínios recém-inscritos.
- **A regra e o termo.** O aceite cobre uma regra (`PentestRule`): o domínio e os subdomínios, as
  exclusões, o catálogo v1 (7 categorias), a janela, a intensidade com os números dela e o IP de saída,
  com um hash canônico. A janela fica fora das 00:00–04:00 de Brasília, dia a dia e com o horário de verão
  de cada fuso. O termo ativo-v1 (`resources/pentest/terms/ativo-v1.md`) e o aviso ao time
  (`aviso-v1.txt`) ficam um arquivo cada, com o hash fixado em `PentestTerms::HASHES`, e os valores do
  cliente saem da regra: mudar uma linha do texto é mudar o arquivo e o hash.
- **O aceite** (`pentest_consents`) guarda três hashes: o do modelo do termo, o da regra e o do texto
  exatamente como foi exibido. Guarda também como a pessoa confirmou que era ela (`reauth_method`). As
  declarações do passo 6 valem para aquele texto: se a regra ou o termo mudar antes do aceite, a pessoa
  lê de novo. Há um aceite vigente por domínio, imutável no banco (só a revogação muda a linha). O
  aceite passivo do Radar ou de Notícias nunca vale.
- **A chave global** (`PentestSwitch`) só liga com as pré-condições que o dono registra:
  - o termo publicado (`PENTEST_TERMS_PUBLISHED_VERSION` e `_AT`);
  - o aviso prévio dado há pelo menos `PENTEST_NOTICE_DAYS` dias (`PENTEST_NOTICE_GIVEN_AT`);
  - o parecer (`PENTEST_LEGAL_OPINION_AT`);
  - a saída separada (`PENTEST_EGRESS_IPS`);
  - a lista de IPs proibidos não vazia (os do Security Monitor, os de `PENTEST_FORBIDDEN_ADDRESSES` e a saída);
  - o alerta de abuse@ chamando o admin no Telegram ou no Discord (`PENTEST_ABUSE_ALERT_AT`).

  Cada leitura confere tudo de novo, e o valor vem do banco, sem cache. Desligada, a área fica em modo
  leitura: dá para preparar o rascunho, mas não para autorizar; revogar funciona sempre.
- **Admin → Testes ativos** (`/admin/testes`): a chave com as pré-condições, Ligar e Desligar, o "Agora" com
  o Parar de cada execução, a fila, as aprovações da 1ª execução (aprovar; recusar com o motivo que o cliente
  vê) e os bastidores de cada execução (a linha do tempo da auditoria, os contadores, Parar, Pausar, Retomar e
  o "liberar reteste", com motivo). O motivo de desligar e de parar tudo fica só na auditoria: o cliente vê
  sempre o texto fixo.
- **"Parar tudo agora" (emergência): só o root religa.** O botão desliga a chave e para todas as execuções
  (inclusive as agendadas e as que esperam a aprovação). Com alguma execução ativa, grava uma trava
  (`pentest.stop_all`, qualquer valor não nulo trava): a chave não liga, nem pelo painel nem forçada no banco,
  e o vigia para a cada minuto o que ainda estiver ativo. Sem execução ativa, o botão é só o Desligar do app, sem
  a trava (com `PENTEST_ALLOWED=false`, diz que a stack já está desligada no servidor). Só o comando no servidor
  apaga a trava, e a chave continua desligada até o admin ligar pelo painel, com a lista de conferência:

```bash
docker compose exec app php artisan secmon:pentest-religar                          # local
docker exec -it "$(docker compose ps -q app)" php artisan secmon:pentest-religar    # produção, na pasta do Security Monitor
```

- **As telas** ficam em `/alertas/testes` (aba Integração, que ganha o cartão "Testes de segurança"): o
  convite ou a lista dos domínios, e os 7 passos da autorização em `/alertas/testes/autorizar/{passo}`, com o
  rascunho no servidor (`pentest_domains.draft`) e o domínio pelo nome (`?dominio=`). Cada passo grava o que
  a regra do aceite confere; o 6 mostra o termo e o 7 autoriza, com a reautenticação recente (o código
  mandado a um app diz a ação e o domínio). Cada domínio tem a página dele
  (`/alertas/testes/dominios/{domínio}`): a posse, o termo aceito, a regra congelada, o registro e revogar,
  que funciona sempre. Com a chave desligada, uma faixa diz se os testes ainda não foram ligados ou se
  estão pausados (desde quando o admin desligou).
- **As execuções** (`pentest_runs`): o "Autorizar e agendar" grava o aceite e agenda a 1ª execução, que
  espera a aprovação de um analista (`pentest_approvals`, em Admin → Testes ativos → Aprovações). Só a
  máquina de estados (`RunStateMachine`) muda o estado e a fase, e o portão de política (`PolicyGate`,
  código determinístico, falha fechada) decide cada entrada em `running`: a chave, o aceite, a janela, a
  posse reconferida no começo de cada fase, o trabalho de todas as fases registrado e um teste por vez no
  servidor. Parar nunca passa pelo portão. Limites por conta: uma execução por domínio a cada 24 h e 4
  por semana (`PENTEST_RUNS_PER_DOMAIN_PER_DAY`, `PENTEST_RUNS_PER_WEEK`). E o teto por site, somando todas
  as contas: 2 a cada 24 h e 6 por semana (`PENTEST_RUNS_PER_HOST_PER_DAY`, `PENTEST_RUNS_PER_HOST_PER_WEEK`);
  o "liberar reteste" do admin tira a execução dele também. Com `PENTEST_ALLOWED=false`, o vigia cancela o que
  ainda não começou.
- **Os passos e o vigia.** Cada fase anda em passos curtos (`pentest_steps`, até 120 s no PHP), rodados
  pelo job `AdvancePentestRun` na fila `pentest` (o worker `fila-pentest`). Um CAS na tentativa impede o
  passo em dobro. O vigia `secmon:pentest-tick`, a cada minuto, redespacha o passo que um deploy matou,
  finaliza a execução parada, confere o portão da que roda, retoma e começa execuções e dá o próximo
  passo. O trabalho de cada fase é uma classe registrada em `secmon.pentest.phases` (chegam da P7 em
  diante); enquanto faltar o de alguma, nenhuma execução começa. Toda ação ativa passa antes pelo portão:
  o orçamento (requisições e tempo de atividade), os campos permitidos, os IPs proibidos, o nome dentro do
  domínio e fora das exclusões, e as portas que a saída bloqueia (`PENTEST_EGRESS_BLOCKED_PORTS`, padrão
  `25,465,587`), que ficam como "não testadas", nunca "fechadas". Enquanto um teste roda num domínio, a
  descoberta, os prints do Radar e a monitoria do admin esperam (`DomainActivity`).
- **Auditoria só de acréscimo** (`pentest_audit`), sem FK, com o número, a hora e a corrente de hash
  postos por um gatilho do banco. UPDATE e TRUNCATE são recusados. DELETE só pela retenção de 5 anos:
  numa transação com `set local secmon.audit_prune = 'on'` e só em linha vencida. Para conferir a corrente:

```bash
docker compose exec app php artisan secmon:pentest-audit-verify
```

---

## Exploits públicos (Exploit-DB)

Quando um caso cita um CVE que tem exploit publicado no Exploit-DB, a matéria
ganha o selo **exploit público** e um bloco com o EDB-ID, o título, o tipo
(remoto, local, aplicação web, negação de serviço), a plataforma, a data, o
selo "verificado" e o link para `exploit-db.com/exploits/<id>`, que é a fonte.
Abaixo do título vem uma explicação escrita pela IA: o que o exploit ataca, o
que precisa para funcionar (autenticação, acesso de rede, interação), o que
consegue e se é um exploit completo ou só uma prova de conceito. **O código
nunca aparece no Security Monitor**, só a explicação e o link.

```
secmon:exploits-sync (todo dia, 05:10 UTC)
   └─ files_exploits.csv do GitLab oficial (~10 MB; só baixa se o ETag mudou)
        └─ exploits + exploit_cve (Postgres) ─▶ CVEs que ganharam ou perderam exploit
             └─ ExploitLinker ... revê os casos que citam esses CVEs (case_exploit)
                  └─ CaseIndex ... reindexa (has_exploit, exploits)
                       └─ ExplainExploit (fila enrich) ... baixa o arquivo e pede a explicação ao hermes
CaseWriter (caso novo ou atualização) ─▶ ExploitLinker ─▶ indexa ─▶ alerta
```

- **Sem API e sem raspar o site**: o Exploit-DB não tem API oficial, e o CSV é
  o mesmo que o `searchsploit` usa. A busca vira um SELECT; o caminho da
  notícia continua sem acessar a rede por causa do exploit.
- **Casos antigos são revistos**: o exploit costuma sair semanas ou meses
  depois da notícia (o do FortiWeb CVE-2025-25257 só entrou em fevereiro de
  2026). Cada sincronização liga os casos que citam os CVEs novos e os
  reindexa. Exploit retirado do índice sai do caso e da busca.
- **Explicação só dos 3 em destaque** de cada caso (os mais recentes; são os
  que a matéria mostra abertos). Um resumo semanal com 17 CVEs não gasta 14
  chamadas de IA; os outros aparecem só com os dados do índice. O arquivo vai
  para a IA entre etiquetas com código novo a cada chamada e a regra de nunca
  seguir instruções de dentro dele; a resposta é limpa de markdown, blocos de
  código e URLs. Falhou, o motivo fica em `exploits.explain_error` e a matéria
  mostra só os dados do índice.
- **Índice cortado não apaga nada**: com menos de 30 mil linhas
  (`secmon.exploits.min_rows`), a sincronização para antes de mexer no banco.
- **Busca**: `has_exploit:true` filtra os casos com exploit (o selo no feed é
  esse filtro) e `exploits:EDB-52473` acha o caso de um exploit.
- **Alertas**: o alerta de um caso que já tem exploit traz o fato
  "Exploit público: EDB-…". O exploit que aparece depois **não** gera alerta
  novo.
- **API**: não muda. Os exploits e o `has_exploit` aparecem só no site, porque
  o formato da API é fixo, campo a campo.

```bash
make exploits-sync                                   # baixa (se mudou), liga os casos e reindexa
docker compose exec app php artisan secmon:exploits-sync --relink   # revê todos os casos com CVE
docker compose exec app php artisan secmon:exploits-explain --sync  # explica os em destaque que faltam
docker compose exec app php artisan secmon:exploits-explain --id=52473 --again --sync
```

---

## Busca

A barra aceita a sintaxe do `query_string` do Elasticsearch:

```
ransomware healthcare
criticity:critical AND victim_sectors:healthcare
threat_actors:"Scattered Spider"
cves:CVE-2026-*
NOT criticity:low
ttps_attack:T1566
iocs:1.2.3.4
```

Campos indexados: `criticity`, `victim_sectors`, `threat_actors`, `cves`,
`iocs`, `malware_families`, `ttps_attack`, `affected_products`, `vendors`,
`attack_type`, `initial_access_vector`, `countries`, `countries_code`,
`loss_type`, `fraud_type`, `data_types_exposed`, `researchers`, `authors`,
`source_confidence`, `latest_source_host`, `title`, `summary`, `has_exploit`,
`exploits`.

Cada campo de indicador é `text` com analisador *keyword + lowercase* e um
sub-campo `.raw` keyword. É isso que faz `threat_actors:"Scattered Spider"`
casar sem depender de caixa **e** `cves:CVE-2026-*` funcionar (o hífen não
quebra o token), mantendo a grafia original nas facetas.

---

## API

API pública de leitura, em JSON:

```
GET /api/news?q=&page=          feed + facetas + paginação
GET /api/news/case/{uuid}       matéria, linha do tempo e indicadores
GET /api/news/updates?limit=8   coletas recentes
GET /api/news/dashboard?window= agregados (30|90|180|365 dias)
```

São endpoints **de leitura**: servem o que já está no banco. Não existe rota de
importação — todo conteúdo entra pelo crawler. Limite de **60 requisições por
minuto por IP** (resposta 429 com `Retry-After`); a busca do feed (`/?q=`) tem o
mesmo limite, e a página inicial sem termo não conta.

---

## Comandos

```bash
make crawl          # roda as fontes que estão no horário
make crawl-all      # força todas as fontes
make enrich         # reprocessa artigos travados na análise
make reanalyze      # refaz a análise do que já foi coletado com o driver atual
make reindex        # recria o índice e reindexa a partir do Postgres
make logs           # acompanha app, worker e scheduler
make shell          # shell no container

make test           # suíte no banco secmon_test, isolado do de produção

# uma fonte só, útil para depurar um feed específico
docker compose exec app php artisan secmon:crawl --source=securityonline.info
```

O código PHP é bind-montado: editar um arquivo já vale na requisição seguinte.
Depois de mexer em **CSS/JS**, rode `make assets`.

> `make test` limpa a config cacheada antes de rodar e recacheia no fim. Sem
> isso, a config gravada no boot ignoraria o banco de teste e a suíte rodaria
> em cima dos dados reais.

---

## Variáveis principais

| Variável | Para quê |
|---|---|
| `UID` / `GID` | UID do host — o container roda com ele para escrever em `storage/` |
| `ELASTICSEARCH_HOST` / `_INDEX` | destino e nome do índice |
| `CRAWLER_ENABLED` | liga/desliga o crawler (o admin sobrepõe) |
| `CRAWLER_MAX_ITEMS_PER_SOURCE` | itens lidos por feed a cada rodada |
| `CRAWLER_DEDUP_THRESHOLD` | limiar de agrupamento (maior = mais casos separados) |
| `ENRICHER_ENABLED` / `ENRICHER_DRIVER` | liga a IA e escolhe o driver |
| `ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL` | credencial e modelo do Claude |
| `OPENAI_API_KEY` / `OPENAI_MODEL` / `OPENAI_BASE_URL` | driver OpenAI-compatível |
| `HERMES_URL` / `HERMES_TOKEN` / `HERMES_MODEL` / `HERMES_EFFORT` | driver hermes (Claude Code da máquina); o token é o do `hermes.conf` |
| `EXPLOITS_ENABLED` / `EXPLOITS_EXPLAIN` | sincronização diária do Exploit-DB e a explicação de cada exploit pela IA (hermes) |
| `GITHUB_SEARCH_TOKEN` | token do GitHub (sem escopo) para a busca em código do monitoramento do GitHub; vazio = só repositórios |
| `GITHUB_AI_TRIAGE` / `GITHUB_AI_REVIEW` | as duas etapas da IA de cada achado do GitHub (classificação e parecer de envio) |
| `PENTEST_ALLOWED` | testes de segurança: a chave do ambiente (desligada); com ela, quem usa é a conta liberada pelo admin em Admin → Usuários |
| `PENTEST_FORBIDDEN_DOMAINS` / `PENTEST_FORBIDDEN_ADDRESSES` | domínios (além do `example.com`) e IPs (além dos do Security Monitor e da saída) que nunca entram nos testes de segurança |
| `PENTEST_EGRESS_IPS` / `PENTEST_TERMS_PUBLISHED_*` / `PENTEST_NOTICE_*` / `PENTEST_LEGAL_OPINION_AT` / `PENTEST_ABUSE_ALERT_AT` | pré-condições da chave global dos testes de segurança, registradas pelo dono |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | credenciais criadas pelo seeder |

---

## Fontes

### Extração do corpo da matéria

O RSS quase nunca traz o texto completo, então o crawler entra na página. A
escolha do bloco é por densidade de parágrafos, e o ruído (relacionadas,
newsletter, share) é filtrado **na leitura**, sem remover nós do DOM — remover
um ancestral cuja classe casava com "share" apagava a matéria inteira em
blogs.cisco.com, unit42 e proofpoint. Quando existe `articleBody` em JSON-LD,
ele tem prioridade.

Duas fontes não entregam o corpo por HTTP simples e ficam só com o resumo do
feed (a matéria ainda é criada, com análise mais rasa):

| Fonte | Motivo |
|---|---|
| `darkreading.com` | WAF devolve HTTP 403 para qualquer User-Agent |
| `sec.cloudapps.cisco.com` | advisory montado por JavaScript |

A Hakai saiu desta lista: o blog foi para `yokai.hakaisecurity.io` (Ghost,
renderizado no servidor) e o feed usado é o da tag `en`, porque cada post é
publicado em pt-br e em en e a análise já gera as duas edições.

O painel **Admin → Coletas** mostra o percentual de extração de cada fonte, para
essa degradação nunca passar despercebida.

### Lista

36 feeds RSS/Atom de fontes de segurança
(securityonline, The Hacker News, BleepingComputer, SecurityWeek, GBHackers,
Security Affairs, The Register, Dark Reading, CyberScoop, Krebs, Schneier,
Unit 42, Securelist, SentinelOne, Check Point, WeLiveSecurity, Tenable, Cisco
PSIRT, Fortinet, Proofpoint, F5 Labs, entre outros) — veja
`database/seeders/SourceSeeder.php`. Dá para adicionar, editar, ligar, desligar
e rodar cada uma sob demanda pelo admin.

---

## Notas operacionais

- **Elasticsearch** pede `vm.max_map_count` alto em algumas máquinas:
  `sudo sysctl -w vm.max_map_count=262144`.
- O nginx resolve o `app` pelo DNS interno a cada requisição, então recriar o
  container da aplicação não derruba o site.
- Os assets ficam em `public/build`; o nginx serve o `public/` do host.
- **Retenção** (`secmon:prune`, todo dia às 03:40 de Brasília; `--dry-run` só
  conta): sessões vencidas (4 h), execuções do crawler (180 dias), jobs que
  falharam (30), texto enviado nas entregas (90, fica o registro) e a própria
  entrega (365), as visitas de anúncio (180), os e-mails enviados (90) e os prints velhos do Radar (90). Os prazos estão em
  `secmon.retention.*` e mudam por variável (`RETENTION_*_DAYS`; 0 desliga um
  item). Excluir um alerta apaga também o que foi enviado para ele.
- **Índices de chave estrangeira:** o `ForeignKeyIndexTest` falha se uma FK nova
  não tiver índice (ou um motivo, na lista do teste). Sem ele, apagar uma linha do
  pai varre a tabela filha inteira.

