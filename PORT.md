# Portar correções do projeto privado para esta versão aberta

Este repositório nasceu de um projeto privado que roda como serviço. As duas
versões **divergiram de propósito**: aqui o programa é uma instalação que você
sobe na sua máquina ou no seu servidor, e lá ele é um serviço com contas,
empresas e travas de operação.

Quem for trazer uma correção de lá para cá precisa saber três coisas: o que
nunca deve ser portado, onde o `cherry-pick` vai bater, e como conferir o
resultado. É isso que este documento guarda.

## O que foi removido aqui — e por isso nunca recebe port

Três PRs tiraram áreas inteiras. Se um commit do projeto privado mexe só nelas,
ele **não tem destino aqui**: feche o port em vez de recriar o código.

### 1. Plano empresarial (PR 1)

Saíram empresas, setores, convites, limites por empresa, auditoria da empresa,
papéis (`CompanyRole`), a área `/empresa` inteira e o recurso de **subdomínios**,
que existia dentro do plano: controllers, form requests, serviços, telas,
e-mails, models (`Company`, `Sector`, `CompanyMember`, `CompanyInvite`,
`CompanyAuditEntry`, `OwnedSubdomain`, `SubdomainAlert`), middlewares, comandos
(`CompanyPurgeCommand`, `CompanyStampCommand`, `SubdomainsDiscoverCommand`),
o `DemoCompanySeeder` e 14 migrations.

A camada de visibilidade passou a responder uma única pergunta: **o item é da
conta que o criou?** Não existem mais `company_id` nem `sector_id` nos models,
nem posse de item por setor, nem aviso de limite por e-mail.

### 2. Login e autenticação (PR 2)

Saiu a autenticação inteira. No lugar há **uma conta só, do dono da
instalação**, criada no primeiro acesso (`User::localOwner`) e autenticada em
toda requisição web pelo middleware `AuthenticateLocalOwner`, sem senha.

Nada disso volta: login, login do admin, cadastro, confirmação de e-mail, troca
de senha e de e-mail, verificação em duas etapas (app autenticador e códigos de
recuperação), reautenticação (senha, código e GitHub), entrar e cadastrar com
GitHub, o captcha da Cloudflare (`Support\Turnstile`), a tela de conta e os
controllers, form requests, serviços, telas e testes dessa área.

> Expor esta instalação na rede abre o painel inteiro para quem alcançar a
> porta. Isso é consequência da decisão acima, não um bug para corrigir.

### 3. Travas da stack de testes de segurança (PR 3)

Saíram as três camadas que decidiam **quem** podia usar a stack:

- `PENTEST_ALLOWED`, que fazia a área responder 404 para todos;
- a chave global do admin (`PentestSwitch`), com as seis pré-condições — termo
  publicado, aviso prévio, parecer jurídico, lista de IPs proibidos, saída
  separada e alerta de `abuse@` —, o "Desligar" e a trava que só o root
  removia; e o `PentestUnlockCommand`;
- a liberação conta por conta (`PentestAccess`) e a aprovação da primeira
  execução por um analista (`PentestApprovals`, `PentestApproval`).

No portão (`PolicyGate`) saíram os códigos **A5a, A5c e A6**; a execução nasce
agendada; e o vigia (`PentestWatch`) perdeu os dois motivos de parada ligados à
chave, mantendo os de orçamento e posse.

**Continuam existindo**, porque protegem quem roda: o "Parar tudo agora"
(`PentestStopAll`, sem depender da chave) e a lista de endereços que nenhum
teste alcança (`ForbiddenAddresses`, vazia por padrão).

## Onde o cherry-pick vai conflitar

Os arquivos abaixo foram reescritos aqui. Um commit de lá que os toque quase
sempre conflita, e o conflito é **real**: o trecho de lá costuma falar de
empresa, de sessão autenticada ou da chave global, coisas que aqui não existem.
Resolva pelo sentido (o que o commit queria consertar) e nunca aceitando o lado
de lá inteiro.

A lista saiu do `git diff main..dev --stat`, agrupada por área.

### Visibilidade e posse (ex-plano empresarial)

| Arquivo | Por que divergiu |
| --- | --- |
| `app/Services/Access/Visibility.php` | nasceu aqui: responde só "é da conta" |
| `app/Services/Access/Channels.php` | idem |
| `app/Services/Access/DeliveryGate.php` | idem |
| `app/Services/Access/Destinations.php` | idem |
| `app/Models/Concerns/VisibleToAccount.php` | sem `company_id`/`sector_id` |
| `app/Models/Contracts/VisibilityRoot.php` | contrato sem dono de empresa |
| `app/Models/Contracts/VisibleThroughRoot.php` | idem |
| `app/Models/AlertProfile.php` | colunas e relações de empresa fora |
| `app/Models/GithubMonitor.php` | idem |
| `app/Models/LookalikeWatch.php` | idem |
| `app/Models/NotificationChannel.php` | idem, e sem `isCompany()` |
| `app/Models/OwnedDomain.php` | idem |
| `app/Services/Domains/DomainOwner.php` | um tipo de dono só (a conta) |
| `app/Services/Domains/DomainOwnership.php` | sem setor, limite nem aviso |
| `app/Services/Alerts/AlertCenter.php` | sem setor nem teto |
| `app/Services/Alerts/AlertPanel.php` | idem |
| `app/Services/Alerts/AlertRouter.php` | idem |
| `app/Services/Alerts/InterviewService.php` | entrevista sem escolher setor |
| `app/Services/Github/GithubInterview.php` | idem |
| `app/Services/Github/GithubPanel.php` | idem |
| `app/Services/Lookalike/RadarInterview.php` | idem |
| `app/Services/Lookalike/LookalikeRadar.php` | idem |
| `app/Services/Lookalike/LookalikeScanner.php` | idem |
| `app/Services/Alerts/Webhook/WebhookConnector.php` | posse sempre da conta |
| `app/Services/Alerts/Webhook/WebhookOwnership.php` | idem |
| `app/Http/Requests/Concerns/TargetsChannel.php` | sem ramo de conta de empresa |
| `app/Http/Requests/Concerns/TargetsAlertProfile.php` | idem |
| `database/seeders/DatabaseSeeder.php` | sem o seeder de empresa de demonstração |

### Autenticação

| Arquivo | Por que divergiu |
| --- | --- |
| `app/Http/Middleware/AuthenticateLocalOwner.php` | nasceu aqui: autentica tudo |
| `app/Models/User.php` | `localOwner()`, sem senha, 2FA nem GitHub |
| `bootstrap/app.php` | middlewares de login, 2FA e reauth fora |
| `app/Http/Middleware/EnsureUserIsAdmin.php` | o dono da instalação é admin |
| `app/Http/Middleware/EnsureSignedLinkActive.php` | sem sessão para conferir |
| `app/Services/Access/RejectedAttempts.php` | veio de `app/Services/Auth/`, sem os motivos de senha e código |
| `app/Services/Admin/UserRemoval.php` | sem as tabelas de login e 2FA |
| `app/Providers/AppServiceProvider.php` | sem os serviços da área de login |
| `config/services.php` | sem GitHub OAuth e Turnstile |
| `resources/views/layouts/app.blade.php` | menu sem conta nem sair |
| `resources/views/layouts/admin.blade.php` | idem |
| `resources/views/admin/users.blade.php` | tela de usuários enxuta |

### Stack de testes de segurança

| Arquivo | Por que divergiu |
| --- | --- |
| `app/Services/Pentest/Engine/PolicyGate.php` | sem os códigos A5a, A5c e A6 |
| `app/Services/Pentest/Engine/PentestWatch.php` | sem as paradas pela chave |
| `app/Services/Pentest/PentestStopAll.php` | nasceu aqui, fora da chave |
| `app/Services/Pentest/ForbiddenAddresses.php` | nasceu aqui, vazia por padrão |
| `app/Services/Pentest/PentestRuns.php` | execução nasce agendada |
| `app/Services/Pentest/PentestPanel.php` | sem liberação nem aprovação |
| `app/Services/Pentest/PentestWizard.php` | idem |
| `app/Services/Pentest/PentestConsents.php` | aceite grava `local` |
| `app/Services/Pentest/PentestEnrollment.php` | sem liberação por conta |
| `app/Services/Pentest/PentestReadyNotice.php` | sem as pré-condições |
| `app/Services/Pentest/PentestBanner.php` | idem |
| `app/Services/Pentest/PentestDomainPage.php` | idem |
| `app/Services/Admin/PentestOverview.php` | painel do admin sem a chave |
| `app/Http/Controllers/Admin/Pentest/StopAllPentestController.php` | usa o `PentestStopAll` |
| `resources/views/admin/pentest/index.blade.php` | tela do admin sem a chave |
| `resources/views/admin/pentest/_tabs.blade.php` | idem |
| `resources/views/alerts/tests/index.blade.php` | sem liberação nem aprovação |
| `resources/views/alerts/tests/partials/start.blade.php` | idem |
| `resources/views/alerts/tests/partials/card.blade.php` | idem |
| `resources/views/alerts/tests/partials/banner.blade.php` | idem |
| `resources/views/alerts/tests/run.blade.php` | idem |
| `resources/views/alerts/tests/authorize.blade.php` | idem |
| `resources/views/alerts/tests/domain.blade.php` | idem |

### Rotas, configuração e documentação

| Arquivo | Por que divergiu |
| --- | --- |
| `routes/web.php` | o que mais divergiu: 269 linhas a menos, sem as rotas de login, conta, empresa e subdomínios |
| `routes/console.php` | sem os agendamentos de empresa e subdomínios |
| `config/secmon.php` | sem `PENTEST_ALLOWED`, empresa e 2FA |
| `.env.example` | sem as chaves dessas áreas |
| `README.md` | descreve a instalação local, não o serviço |

### Esquema do banco

As 14 migrations de empresa, setor, convite, auditoria, subdomínio e limite, as
de 2FA (`user_two_factor`), as de `github` em `users`, de `pentest_approvals` e
de `pentest_access` **foram apagadas**, não revertidas: aqui essas tabelas e
colunas nunca existem. Uma migration de lá que mexa nelas não tem destino aqui.
A única migration que divergiu sem ser apagada é
`database/migrations/2026_12_20_000100_create_key_sites_and_exposed_keys_tables.php`.

### Testes

Saíram 114 arquivos de teste (a pasta `tests/Feature/Company/` inteira,
`tests/Feature/Account/`, os de login, 2FA, Turnstile, cadastro, GitHub, e
`PentestSwitchTest`, `PentestAccessTest` e `PentestReadyNoticeGuardsTest`). Um
commit de lá que traga teste novo para essas áreas não tem destino aqui; um que
mexa num teste que sobrou tende a conflitar nos *helpers*, porque eles perderam
os passos de login e de liberação.

## Como portar, na prática

1. Leia o commit de lá antes de mexer: se ele só toca área removida, feche o
   port e anote o motivo.
2. `git cherry-pick -x <commit>` — o `-x` deixa no histórico de qual commit
   veio.
3. Nos conflitos, resolva pelo **sentido** do commit. Se a única forma de
   aplicar for trazer empresa, sessão de login ou chave global de volta,
   reescreva a correção sem elas.
4. Rode o CI local antes de abrir a PR:
   - `vendor/bin/pint`
   - `python3 -I ci/verificadores/orfaos.py`
   - `python3 -I ci/verificadores/nomes2.py`

   Os dois verificadores existem justamente por causa desta divergência: eles
   acham referência a classe que não existe mais e `use` que ficou para trás —
   o resto típico de uma remoção —, e o `php -l` não vê nenhum dos dois.
5. A suíte PHPUnit precisa de Postgres e Elasticsearch de pé, e ainda não roda
   no CI. Rode-a na máquina antes de dizer que o port está pronto.
