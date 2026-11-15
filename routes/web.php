<?php

use App\Http\Controllers\Admin\Alerts\Catalog\ShowCatalogController;
use App\Http\Controllers\Admin\Alerts\Catalog\StoreTechController;
use App\Http\Controllers\Admin\Alerts\Catalog\ToggleTechController;
use App\Http\Controllers\Admin\Alerts\Catalog\UpdateTechController;
use App\Http\Controllers\Admin\Alerts\Clients\ListClientsController;
use App\Http\Controllers\Admin\Alerts\Clients\RecompileClientController;
use App\Http\Controllers\Admin\Alerts\Clients\ShowClientController;
use App\Http\Controllers\Admin\Alerts\Clients\TestClientController;
use App\Http\Controllers\Admin\Alerts\Clients\ToggleClientPauseController;
use App\Http\Controllers\Admin\Alerts\Clients\UpdateClientTechController;
use App\Http\Controllers\Admin\Alerts\CustomAnswers\ListCustomAnswersController;
use App\Http\Controllers\Admin\Alerts\CustomAnswers\RegenerateCustomAnswerController;
use App\Http\Controllers\Admin\Alerts\CustomAnswers\UpdateCustomAnswerController;
use App\Http\Controllers\Admin\Alerts\Deliveries\ListDeliveriesController;
use App\Http\Controllers\Admin\Alerts\Deliveries\RetryDeliveryController;
use App\Http\Controllers\Admin\Alerts\Domains\ListDomainsController;
use App\Http\Controllers\Admin\Alerts\Domains\RunDomainDiscoveryController;
use App\Http\Controllers\Admin\Alerts\Questions\DestroyQuestionController;
use App\Http\Controllers\Admin\Alerts\Questions\ListQuestionsController;
use App\Http\Controllers\Admin\Alerts\Questions\MoveQuestionController;
use App\Http\Controllers\Admin\Alerts\Questions\StoreQuestionController;
use App\Http\Controllers\Admin\Alerts\Questions\UpdateQuestionController;
use App\Http\Controllers\Admin\Alerts\RecompileProfilesController;
use App\Http\Controllers\Admin\Alerts\RunAlertsMatchController;
use App\Http\Controllers\Admin\Alerts\SendDigestsController;
use App\Http\Controllers\Admin\Alerts\ShowAlertsOverviewController;
use App\Http\Controllers\Admin\Alerts\UpdateAlertsConfigController;
use App\Http\Controllers\Admin\Articles\EditArticleTextController;
use App\Http\Controllers\Admin\Articles\RebuildWithoutAiController;
use App\Http\Controllers\Admin\Articles\RetryArticleController;
use App\Http\Controllers\Admin\Articles\ShowCollectionsController;
use App\Http\Controllers\Admin\Articles\UpdateArticleTextController;
use App\Http\Controllers\Admin\Cases\DestroyCaseController;
use App\Http\Controllers\Admin\Cases\ReindexCasesController;
use App\Http\Controllers\Admin\Cases\ToggleCaseVisibilityController;
use App\Http\Controllers\Admin\Emails\SendTestEmailController;
use App\Http\Controllers\Admin\Emails\ShowEmailsController;
use App\Http\Controllers\Admin\Logbook\DestroyLogEntryController;
use App\Http\Controllers\Admin\Logbook\ListLogEntriesController;
use App\Http\Controllers\Admin\Logbook\StoreLogEntryController;
use App\Http\Controllers\Admin\Logbook\UpdateLogEntryController;
use App\Http\Controllers\Admin\Monitoring\AddMyAddressController;
use App\Http\Controllers\Admin\Monitoring\AddOwnAddressController;
use App\Http\Controllers\Admin\Monitoring\DestroyAccessController;
use App\Http\Controllers\Admin\Monitoring\DestroyAccessesByIpController;
use App\Http\Controllers\Admin\Monitoring\ShowAccessAttacksController;
use App\Http\Controllers\Admin\Monitoring\ShowAccessesController;
use App\Http\Controllers\Admin\Monitoring\ShowAccessIpController;
use App\Http\Controllers\Admin\Monitoring\ShowAccessOriginsController;
use App\Http\Controllers\Admin\Monitoring\ShowAccessRobotsController;
use App\Http\Controllers\Admin\Monitoring\ShowAccessSettingsController;
use App\Http\Controllers\Admin\Monitoring\ToggleAccessMonitoringController;
use App\Http\Controllers\Admin\Monitoring\UpdateOwnAddressesController;
use App\Http\Controllers\Admin\Pentest\AllowPentestRetestController;
use App\Http\Controllers\Admin\Pentest\ApprovePentestRunController;
use App\Http\Controllers\Admin\Pentest\DisablePentestController;
use App\Http\Controllers\Admin\Pentest\EnablePentestController;
use App\Http\Controllers\Admin\Pentest\ListAdminPentestRunsController;
use App\Http\Controllers\Admin\Pentest\ListPentestApprovalsController;
use App\Http\Controllers\Admin\Pentest\PauseAdminPentestRunController;
use App\Http\Controllers\Admin\Pentest\RejectPentestRunController;
use App\Http\Controllers\Admin\Pentest\ResumeAdminPentestRunController;
use App\Http\Controllers\Admin\Pentest\ShowAdminPentestRunController;
use App\Http\Controllers\Admin\Pentest\ShowPentestApprovalController;
use App\Http\Controllers\Admin\Pentest\ShowPentestOverviewController;
use App\Http\Controllers\Admin\Pentest\StopAdminPentestRunController;
use App\Http\Controllers\Admin\Pentest\StopAllPentestController;
use App\Http\Controllers\Admin\Prompts\ListPromptsController;
use App\Http\Controllers\Admin\Prompts\ResetPromptController;
use App\Http\Controllers\Admin\Prompts\RestorePromptRevisionController;
use App\Http\Controllers\Admin\Prompts\ShowPromptStageController;
use App\Http\Controllers\Admin\Prompts\ToggleStageController;
use App\Http\Controllers\Admin\Prompts\UpdatePromptController;
use App\Http\Controllers\Admin\Queue\AdvanceJobController;
use App\Http\Controllers\Admin\Queue\AdvanceScheduledJobsController;
use App\Http\Controllers\Admin\Queue\DestroyFailedJobController;
use App\Http\Controllers\Admin\Queue\DestroyFailedJobsController;
use App\Http\Controllers\Admin\Queue\RetryFailedJobController;
use App\Http\Controllers\Admin\Queue\RetryFailedJobsController;
use App\Http\Controllers\Admin\Queue\ShowQueueController;
use App\Http\Controllers\Admin\Security\DestroySecuritySiteController;
use App\Http\Controllers\Admin\Security\ListSecuritySitesController;
use App\Http\Controllers\Admin\Security\RecheckSecurityOwnershipController;
use App\Http\Controllers\Admin\Security\RunSecurityScanController;
use App\Http\Controllers\Admin\Security\ShowSecuritySiteController;
use App\Http\Controllers\Admin\Security\StoreSecuritySiteController;
use App\Http\Controllers\Admin\Security\ToggleSecuritySiteController;
use App\Http\Controllers\Admin\Settings\ShowSettingsController;
use App\Http\Controllers\Admin\Settings\UpdateSettingsController;
use App\Http\Controllers\Admin\ShowAdminDashboardController;
use App\Http\Controllers\Admin\Sources\DestroySourceController;
use App\Http\Controllers\Admin\Sources\ListCrawlRunsController;
use App\Http\Controllers\Admin\Sources\ListSourcesController;
use App\Http\Controllers\Admin\Sources\RunAllSourcesController;
use App\Http\Controllers\Admin\Sources\RunSourceController;
use App\Http\Controllers\Admin\Sources\StoreSourceController;
use App\Http\Controllers\Admin\Sources\ToggleSourceController;
use App\Http\Controllers\Admin\Sources\UpdateSourceController;
use App\Http\Controllers\Admin\Users\DestroyUserController;
use App\Http\Controllers\Admin\Users\GrantPentestAccessController;
use App\Http\Controllers\Admin\Users\ListUsersController;
use App\Http\Controllers\Admin\Users\RevokePentestAccessController;
use App\Http\Controllers\Alerts\Center\ToggleChannelPauseController;
use App\Http\Controllers\Alerts\Center\UpdateAlertCenterController;
use App\Http\Controllers\Alerts\DestroyAlertController;
use App\Http\Controllers\Alerts\Discord\ConnectDiscordController;
use App\Http\Controllers\Alerts\Discord\DestroyDiscordController;
use App\Http\Controllers\Alerts\Discord\DiscordCallbackController;
use App\Http\Controllers\Alerts\Discord\SelectDiscordChannelController;
use App\Http\Controllers\Alerts\Discord\ShowDiscordController;
use App\Http\Controllers\Alerts\Discord\StoreDiscordWebhookController;
use App\Http\Controllers\Alerts\Discord\TestDiscordController;
use App\Http\Controllers\Alerts\Discord\UpdateDiscordController;
use App\Http\Controllers\Alerts\Domain\DecideDnsSuggestionsController;
use App\Http\Controllers\Alerts\Domain\DecideFindingController;
use App\Http\Controllers\Alerts\Domain\RerunDomainWallsController;
use App\Http\Controllers\Alerts\Domain\VerifyDomainController;
use App\Http\Controllers\Alerts\Github\DestroyGithubMonitorController;
use App\Http\Controllers\Alerts\Github\FinishGithubInterviewController;
use App\Http\Controllers\Alerts\Github\ResendGithubFindingController;
use App\Http\Controllers\Alerts\Github\ScanGithubNowController;
use App\Http\Controllers\Alerts\Github\ShowGithubController;
use App\Http\Controllers\Alerts\Github\ShowGithubFindingController;
use App\Http\Controllers\Alerts\Github\ShowGithubInterviewStepController;
use App\Http\Controllers\Alerts\Github\SignedGithubNoteController;
use App\Http\Controllers\Alerts\Github\SignedGithubVoteController;
use App\Http\Controllers\Alerts\Github\StoreGithubFeedbackController;
use App\Http\Controllers\Alerts\Github\ToggleGithubPauseController;
use App\Http\Controllers\Alerts\Github\UpdateGithubInterviewStepController;
use App\Http\Controllers\Alerts\Interview\FinishInterviewController;
use App\Http\Controllers\Alerts\Interview\ShowInterviewStepController;
use App\Http\Controllers\Alerts\Interview\UpdateInterviewStepController;
use App\Http\Controllers\Alerts\Keys\ClassifyExposedKeyController;
use App\Http\Controllers\Alerts\Keys\PauseKeyDomainController;
use App\Http\Controllers\Alerts\Keys\ResumeKeyDomainController;
use App\Http\Controllers\Alerts\Keys\ScanKeyDomainController;
use App\Http\Controllers\Alerts\Keys\ShowKeyDomainController;
use App\Http\Controllers\Alerts\Keys\ShowKeysController;
use App\Http\Controllers\Alerts\Keys\UpdateKeyDomainPathsController;
use App\Http\Controllers\Alerts\Lookalikes\ActivateRadarController;
use App\Http\Controllers\Alerts\Lookalikes\DestroyRadarController;
use App\Http\Controllers\Alerts\Lookalikes\ScanLookalikesNowController;
use App\Http\Controllers\Alerts\Lookalikes\ShowAbuseKitController;
use App\Http\Controllers\Alerts\Lookalikes\ShowAlertPhotoController;
use App\Http\Controllers\Alerts\Lookalikes\ShowCaptureShotController;
use App\Http\Controllers\Alerts\Lookalikes\ShowLookalikeController;
use App\Http\Controllers\Alerts\Lookalikes\ShowLookalikesController;
use App\Http\Controllers\Alerts\Lookalikes\ShowRadarInterviewStepController;
use App\Http\Controllers\Alerts\Lookalikes\ShowReferenceShotController;
use App\Http\Controllers\Alerts\Lookalikes\SignedLookalikeNoteController;
use App\Http\Controllers\Alerts\Lookalikes\SignedLookalikeVerdictController;
use App\Http\Controllers\Alerts\Lookalikes\StoreLookalikeVerdictController;
use App\Http\Controllers\Alerts\Lookalikes\ToggleAbuseReportController;
use App\Http\Controllers\Alerts\Lookalikes\ToggleLookalikesPauseController;
use App\Http\Controllers\Alerts\Lookalikes\UpdateRadarInterviewStepController;
use App\Http\Controllers\Alerts\Lookalikes\VerifyRadarDomainController;
use App\Http\Controllers\Alerts\News\ShowNewsController;
use App\Http\Controllers\Alerts\ReadAlertCaseController;
use App\Http\Controllers\Alerts\ResendAlertController;
use App\Http\Controllers\Alerts\ShowAlertsController;
use App\Http\Controllers\Alerts\ShowIntegrationsController;
use App\Http\Controllers\Alerts\ShowNotifiersController;
use App\Http\Controllers\Alerts\SignedFeedbackNoteController;
use App\Http\Controllers\Alerts\SignedIrrelevantController;
use App\Http\Controllers\Alerts\SignedRelevantController;
use App\Http\Controllers\Alerts\Slack\ConnectSlackController;
use App\Http\Controllers\Alerts\Slack\DestroySlackController;
use App\Http\Controllers\Alerts\Slack\ShowSlackController;
use App\Http\Controllers\Alerts\Slack\SlackCallbackController;
use App\Http\Controllers\Alerts\Slack\StoreSlackWebhookController;
use App\Http\Controllers\Alerts\Slack\TestSlackController;
use App\Http\Controllers\Alerts\Slack\UpdateSlackController;
use App\Http\Controllers\Alerts\StoreAlertController;
use App\Http\Controllers\Alerts\StoreAlertFeedbackController;
use App\Http\Controllers\Alerts\Telegram\CancelTelegramLinkController;
use App\Http\Controllers\Alerts\Telegram\ConnectTelegramController;
use App\Http\Controllers\Alerts\Telegram\DestroyTelegramController;
use App\Http\Controllers\Alerts\Telegram\ShowTelegramController;
use App\Http\Controllers\Alerts\Telegram\ShowTelegramLinkStatusController;
use App\Http\Controllers\Alerts\Telegram\TestTelegramController;
use App\Http\Controllers\Alerts\Telegram\UpdateTelegramController;
use App\Http\Controllers\Alerts\Tests\AcceptPentestConsentController;
use App\Http\Controllers\Alerts\Tests\DestroyPentestDomainController;
use App\Http\Controllers\Alerts\Tests\PausePentestRunController;
use App\Http\Controllers\Alerts\Tests\RequestPentestAccessController;
use App\Http\Controllers\Alerts\Tests\ResumePentestRunController;
use App\Http\Controllers\Alerts\Tests\RevokePentestConsentController;
use App\Http\Controllers\Alerts\Tests\ShowPentestBannerController;
use App\Http\Controllers\Alerts\Tests\ShowPentestConsentController;
use App\Http\Controllers\Alerts\Tests\ShowPentestController;
use App\Http\Controllers\Alerts\Tests\ShowPentestDomainController;
use App\Http\Controllers\Alerts\Tests\ShowPentestRunController;
use App\Http\Controllers\Alerts\Tests\ShowPentestRunStatusController;
use App\Http\Controllers\Alerts\Tests\ShowPentestStepController;
use App\Http\Controllers\Alerts\Tests\StartPentestRunController;
use App\Http\Controllers\Alerts\Tests\StopPentestRunController;
use App\Http\Controllers\Alerts\Tests\StorePentestDomainController;
use App\Http\Controllers\Alerts\Tests\UpdatePentestStepController;
use App\Http\Controllers\Alerts\Tests\VerifyPentestDomainController;
use App\Http\Controllers\Alerts\ToggleAlertPauseController;
use App\Http\Controllers\Alerts\Webhooks\DestroyWebhookController;
use App\Http\Controllers\Alerts\Webhooks\ShowNewWebhookController;
use App\Http\Controllers\Alerts\Webhooks\ShowWebhookController;
use App\Http\Controllers\Alerts\Webhooks\StoreWebhookController;
use App\Http\Controllers\Alerts\Webhooks\TestWebhookController;
use App\Http\Controllers\Alerts\Webhooks\UpdateWebhookController;
use App\Http\Controllers\Alerts\Webhooks\UpdateWebhookEndpointController;
use App\Http\Controllers\Alerts\Webhooks\UpdateWebhookSecretController;
use App\Http\Controllers\Alerts\Webhooks\VerifyWebhookDomainController;
use App\Http\Controllers\Site\ShowAboutController;
use App\Http\Controllers\Site\ShowCaseController;
use App\Http\Controllers\Site\ShowDashboardController;
use App\Http\Controllers\Site\ShowFeedController;
use App\Http\Controllers\Site\ShowSecurityTxtController;
use App\Http\Controllers\Site\ShowSitemapController;
use App\Http\Controllers\Site\StorePageViewDurationController;
use App\Http\Controllers\Site\SwitchLanguageController;
use App\Services\Github\GithubFeedback;
use App\Services\Lookalike\AbuseKit;
use App\Services\Prompts\PromptCatalog;
use App\Support\ReportLanguage;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;

// Cada rota tem um controller de uma ação só (__invoke), que valida pelo seu
// form request e passa o trabalho a um service: rota → middleware → controller → service.

Route::get('/', ShowFeedController::class)->middleware('throttle:busca')->name('feed.index');
Route::get('/dashboard', ShowDashboardController::class)->name('feed.dashboard');
Route::get('/sobre', ShowAboutController::class)->name('about');
Route::view('/termos', 'legal.terms')->name('terms');
Route::view('/privacidade', 'legal.privacy')->name('privacy');
// A quem avisar de uma falha no Security Monitor ou de tráfego vindo dele (RFC 9116); o endereço antigo leva ao novo.
Route::get('/.well-known/security.txt', ShowSecurityTxtController::class)->name('security-txt');
Route::get('/security.txt', RedirectController::class)->defaults('destination', '/.well-known/security.txt')->defaults('status', 301);
// O mapa do site para os buscadores, sem o grupo web: o robô que o lê não abre sessão no banco nem leva cookie.
// Num site grande (Sitemap::file), o /sitemap.xml lista as partes, que só existem a partir daí.
Route::get('/sitemap.xml', ShowSitemapController::class)->withoutMiddleware('web')->name('sitemap');
Route::get('/sitemap-{part}.xml', ShowSitemapController::class)->whereNumber('part')->withoutMiddleware('web')->name('sitemap.part');
Route::get('/materia/{uuid}', ShowCaseController::class)->whereUuid('uuid')->name('feed.show');
// Admin → Acessos: o navegador manda, ao sair, quanto tempo a página ficou aberta.
Route::post('/acessos/tempo', StorePageViewDurationController::class)->middleware('throttle:60,1')->name('page-views.duration');
Route::get('/idioma/{lang}', SwitchLanguageController::class)
    ->whereIn('lang', array_keys(ReportLanguage::LABELS))
    ->name('report-lang');

// Com login, cada limite (`throttle:N,M`) tem o seu contador, no 3º parâmetro. Sem ele, a chave do limite
// é só a conta: todos os limites dela somam no mesmo contador, e gravar a entrevista do Radar (30/min)
// esgotava o "Verificar agora" (6/min). O RouteTableTest cobra o contador próprio em toda rota com login.
Route::middleware('auth')->group(function () {
    // As execuções dos testes de segurança, pelo número (uuid): ver, a situação para a consulta periódica, Parar e a
    // faixa de qualquer página. Fora do `verified` (M1 do Red Team na P6a): quem trocou o e-mail e ainda não confirmou
    // o novo continua vendo e parando o próprio teste. A execução é resolvida só entre as da conta (outra dá 404), e
    // sem PENTEST_ALLOWED tudo responde 404. Parar (e cancelar antes de começar) nunca é recusado por limite. A
    // consulta periódica não renova a sessão (item 6 do Blue Team). Revogar e excluir o domínio também ficam aqui
    // (item 4 do Blue Team): revogar é retirar o consentimento, e vale a qualquer momento; o domínio vai pelo nome,
    // só entre os da conta, sem limite e sem reautenticação.
    Route::prefix('alertas')->name('alerts.')->middleware('pentest.available')->group(function () {
        Route::get('/testes/execucoes/{execucao}', ShowPentestRunController::class)->whereUuid('execucao')->name('tests.runs.show');
        Route::get('/testes/execucoes/{execucao}/estado', ShowPentestRunStatusController::class)->whereUuid('execucao')->middleware(['session.idle', 'throttle:60,1,pentest-estado'])->name('tests.runs.status');
        Route::post('/testes/execucoes/{execucao}/parar', StopPentestRunController::class)->whereUuid('execucao')->name('tests.runs.stop');
        Route::get('/testes/faixa', ShowPentestBannerController::class)->middleware(['session.idle', 'throttle:60,1,pentest-faixa'])->name('tests.banner');
        Route::delete('/testes/dominios/{dominio}', DestroyPentestDomainController::class)->name('tests.domains.destroy');
        Route::delete('/testes/dominios/{dominio}/termo', RevokePentestConsentController::class)->name('tests.domains.revoke');
    });

    // Alertas: o hub (tudo o que saiu), a Integração (que abre Notícias, Radar e GitHub) e os notificadores.
    // Só com o e-mail confirmado: sem ele, o `verified` manda para o aviso de confirmação.
    Route::prefix('alertas')->name('alerts.')->group(function () {
        Route::get('/', ShowAlertsController::class)->name('index');
        Route::get('/integracao', ShowIntegrationsController::class)->name('integrations');

        // Notícias: um alerta por produto ou site, cada um com a sua entrevista (a de sempre, em /alertas/entrevista).
        Route::get('/noticias', ShowNewsController::class)->name('news');
        Route::post('/novo', StoreAlertController::class)->middleware('throttle:10,1,alerta-novo')->name('store');
        Route::post('/pausar', ToggleAlertPauseController::class)->name('pause');
        Route::delete('/', DestroyAlertController::class)->name('destroy');
        Route::post('/{match}/feedback', StoreAlertFeedbackController::class)->whereNumber('match')->middleware('throttle:30,1,alerta-avaliar')->name('feedback');
        Route::post('/{match}/reenviar', ResendAlertController::class)->whereNumber('match')->middleware('throttle:10,1,alerta-reenviar')->name('resend');

        // Notificadores: por onde os alertas chegam (Slack, Discord, Telegram, webhook), o que cada app recebe
        // e a pausa do envio, sem desconectar. A antiga Central virou parte desta tela; o endereço dela
        // (que segue nas mensagens já enviadas) leva para cá. Só o GET: o Route::redirect atende todos
        // os métodos e, com as rotas em cache (produção), engolia o PUT de salvar logo abaixo.
        Route::get('/notificadores', ShowNotifiersController::class)->name('notifiers');
        Route::get('/central', RedirectController::class)->defaults('destination', '/alertas/notificadores')->defaults('status', 301)->name('center');
        Route::put('/central', UpdateAlertCenterController::class)->name('center.update');
        Route::post('/central/{channel}/pausar', ToggleChannelPauseController::class)->whereNumber('channel')->name('center.pause');
        Route::get('/discord', ShowDiscordController::class)->name('discord');
        Route::put('/discord', UpdateDiscordController::class)->name('discord.update');
        Route::delete('/discord', DestroyDiscordController::class)->name('discord.destroy');
        Route::get('/discord/conectar/{modo}', ConnectDiscordController::class)->whereIn('modo', ['canal', 'dm', 'comunidade'])->name('discord.connect');
        Route::get('/discord/callback', DiscordCallbackController::class)->middleware('throttle:10,1,discord-volta')->name('discord.callback');
        Route::post('/discord/canal', SelectDiscordChannelController::class)->middleware('throttle:10,1,discord-canal')->name('discord.channel');
        Route::post('/discord/webhook', StoreDiscordWebhookController::class)->middleware('throttle:10,1,discord-webhook')->name('discord.webhook');
        Route::post('/discord/teste', TestDiscordController::class)->middleware('throttle:3,1,discord-teste')->name('discord.test');

        Route::get('/telegram', ShowTelegramController::class)->name('telegram');
        Route::put('/telegram', UpdateTelegramController::class)->name('telegram.update');
        Route::delete('/telegram', DestroyTelegramController::class)->name('telegram.destroy');
        Route::post('/telegram/conectar', ConnectTelegramController::class)->middleware('throttle:10,1,telegram-conectar')->name('telegram.connect');
        Route::delete('/telegram/conectar', CancelTelegramLinkController::class)->name('telegram.cancel');
        Route::get('/telegram/conectar/status', ShowTelegramLinkStatusController::class)->middleware('throttle:40,1,telegram-situacao')->name('telegram.status');
        Route::post('/telegram/teste', TestTelegramController::class)->middleware('throttle:3,1,telegram-teste')->name('telegram.test');

        Route::get('/slack', ShowSlackController::class)->name('slack');
        Route::put('/slack', UpdateSlackController::class)->name('slack.update');
        Route::delete('/slack', DestroySlackController::class)->name('slack.destroy');
        Route::post('/slack/webhook', StoreSlackWebhookController::class)->middleware('throttle:10,1,slack-webhook')->name('slack.webhook');
        Route::post('/slack/teste', TestSlackController::class)->middleware('throttle:3,1,slack-teste')->name('slack.test');
        Route::get('/slack/conectar', ConnectSlackController::class)->name('slack.oauth');
        Route::get('/slack/callback', SlackCallbackController::class)->middleware('throttle:10,1,slack-volta')->name('slack.callback');

        // Webhook: o Security Monitor chama a URL do cliente (num domínio comprovado) com o JSON assinado. Até 5 por conta.
        Route::get('/webhooks/novo', ShowNewWebhookController::class)->name('webhooks.create');
        Route::post('/webhooks', StoreWebhookController::class)->middleware('throttle:10,1,webhook-novo')->name('webhooks.store');
        Route::get('/webhooks/{webhook}', ShowWebhookController::class)->whereNumber('webhook')->name('webhooks.show');
        Route::put('/webhooks/{webhook}', UpdateWebhookController::class)->whereNumber('webhook')->name('webhooks.update');
        Route::delete('/webhooks/{webhook}', DestroyWebhookController::class)->whereNumber('webhook')->name('webhooks.destroy');
        Route::put('/webhooks/{webhook}/endereco', UpdateWebhookEndpointController::class)->whereNumber('webhook')->middleware('throttle:10,1,webhook-endereco')->name('webhooks.endpoint');
        Route::put('/webhooks/{webhook}/autenticacao', UpdateWebhookSecretController::class)->whereNumber('webhook')->middleware('throttle:10,1,webhook-segredo')->name('webhooks.secret');
        Route::post('/webhooks/{webhook}/verificar', VerifyWebhookDomainController::class)->whereNumber('webhook')->middleware('throttle:6,1,webhook-posse')->name('webhooks.verify');
        Route::post('/webhooks/{webhook}/teste', TestWebhookController::class)->whereNumber('webhook')->middleware('throttle:3,1,webhook-teste')->name('webhooks.test');

        Route::post('/dominio/verificar', VerifyDomainController::class)->middleware('throttle:6,1,noticias-posse')->name('domain.verify');
        Route::post('/dominio/rodar', RerunDomainWallsController::class)->middleware('throttle:6,1,noticias-reler')->name('domain.rerun');
        Route::post('/dominio/sugestoes', DecideDnsSuggestionsController::class)->middleware('throttle:20,1,noticias-sugestoes')->name('domain.suggestions');
        Route::post('/sugestoes/{finding}/{acao}', DecideFindingController::class)
            ->whereNumber('finding')->whereIn('acao', ['aceitar', 'ignorar'])->name('finding.decide');

        // Radar de domínios: entrevista própria (domínio, marca, posse) e nomes parecidos com o domínio, com prints, parecer da IA e kit de denúncia.
        Route::get('/radar-de-dominios', ShowLookalikesController::class)->name('lookalikes');
        Route::delete('/radar-de-dominios', DestroyRadarController::class)->name('lookalikes.destroy');
        Route::get('/radar-de-dominios/entrevista/{passo}', ShowRadarInterviewStepController::class)->whereNumber('passo')->name('lookalikes.interview');
        Route::put('/radar-de-dominios/entrevista/{passo}', UpdateRadarInterviewStepController::class)->whereNumber('passo')->middleware('throttle:30,1,radar-entrevista')->name('lookalikes.interview.update');
        Route::post('/radar-de-dominios/entrevista/verificar', VerifyRadarDomainController::class)->middleware('throttle:6,1,radar-posse')->name('lookalikes.interview.verify');
        Route::post('/radar-de-dominios/entrevista/ativar', ActivateRadarController::class)->middleware('throttle:10,1,radar-ativar')->name('lookalikes.interview.activate');
        Route::post('/radar-de-dominios/verificar', ScanLookalikesNowController::class)->middleware('throttle:6,1,radar-varredura')->name('lookalikes.scan');
        Route::post('/radar-de-dominios/pausar', ToggleLookalikesPauseController::class)->name('lookalikes.pause');
        Route::get('/radar-de-dominios/{lookalike}', ShowLookalikeController::class)->whereNumber('lookalike')->name('lookalikes.show');
        Route::post('/radar-de-dominios/{lookalike}/avaliar', StoreLookalikeVerdictController::class)->whereNumber('lookalike')->middleware('throttle:30,1,radar-parecer')->name('lookalikes.verdict');
        Route::get('/radar-de-dominios/{lookalike}/denuncia', ShowAbuseKitController::class)->whereNumber('lookalike')->name('lookalikes.kit');
        Route::post('/radar-de-dominios/{lookalike}/denuncia/{canal}', ToggleAbuseReportController::class)
            ->whereNumber('lookalike')->whereIn('canal', array_keys(AbuseKit::CHANNELS))->middleware('throttle:30,1,radar-denuncia')->name('lookalikes.report');
        Route::get('/radar-de-dominios/print/{capture}/{aparelho}/{momento?}', ShowCaptureShotController::class)
            ->whereNumber('capture')->whereIn('aparelho', ['desktop', 'android', 'iphone'])->whereIn('momento', ['first', 'depois'])->name('lookalikes.shot');
        Route::get('/radar-de-dominios/referencia/{watch}/{pagina}/{aparelho}', ShowReferenceShotController::class)
            ->whereNumber('watch')->whereNumber('pagina')->whereIn('aparelho', ['desktop', 'android', 'iphone'])->name('lookalikes.reference');

        // Monitoramento do GitHub: entrevista própria, varredura diária e duas etapas de IA por achado.
        Route::get('/github', ShowGithubController::class)->name('github');
        Route::delete('/github', DestroyGithubMonitorController::class)->name('github.destroy');
        Route::get('/github/entrevista/{passo}', ShowGithubInterviewStepController::class)->whereNumber('passo')->name('github.interview');
        Route::put('/github/entrevista/{passo}', UpdateGithubInterviewStepController::class)->whereNumber('passo')->middleware('throttle:30,1,github-entrevista')->name('github.interview.update');
        Route::post('/github/entrevista/concluir', FinishGithubInterviewController::class)->middleware('throttle:10,1,github-concluir')->name('github.interview.finish');
        Route::post('/github/verificar', ScanGithubNowController::class)->middleware('throttle:6,1,github-varredura')->name('github.scan');
        Route::post('/github/pausar', ToggleGithubPauseController::class)->name('github.pause');
        Route::get('/github/{finding}', ShowGithubFindingController::class)->whereNumber('finding')->name('github.show');
        Route::post('/github/{finding}/avaliar', StoreGithubFeedbackController::class)->whereNumber('finding')->middleware('throttle:30,1,github-avaliar')->name('github.feedback');
        Route::post('/github/{finding}/enviar', ResendGithubFindingController::class)->whereNumber('finding')->middleware('throttle:10,1,github-reenviar')->name('github.resend');

        // Chaves expostas: a varredura só de leitura dos domínios comprovados (a posse é a autorização) e a decisão do dono
        // sobre cada chave. Ver basta ver o domínio; varrer, pausar e decidir pedem configurá-lo (KeyDomainRequest).
        Route::get('/chaves', ShowKeysController::class)->name('keys');
        Route::get('/chaves/{dominio}', ShowKeyDomainController::class)->name('keys.domain');
        Route::post('/chaves/{dominio}/varrer', ScanKeyDomainController::class)->middleware('throttle:10,1,chaves-varrer')->name('keys.scan');
        Route::post('/chaves/{dominio}/pausar', PauseKeyDomainController::class)->middleware('throttle:20,1,chaves-pausar')->name('keys.pause');
        Route::post('/chaves/{dominio}/retomar', ResumeKeyDomainController::class)->middleware('throttle:20,1,chaves-retomar')->name('keys.resume');
        Route::put('/chaves/{dominio}/enderecos', UpdateKeyDomainPathsController::class)->middleware('throttle:20,1,chaves-enderecos')->name('keys.paths');
        Route::put('/chaves/{dominio}/{chave}', ClassifyExposedKeyController::class)->whereNumber('chave')->middleware('throttle:60,1,chaves-decidir')->name('keys.classify');

        // Testes de segurança (teste ativo, só com autorização): a lista, os passos da autorização, a posse e
        // a página de cada domínio. Sem PENTEST_ALLOWED, tudo responde 404. O domínio vai pelo nome. Cada
        // limite tem o seu contador (o 3º parâmetro): sem ele, todo `throttle:N,M` da conta soma no mesmo, e
        // gravar os passos em sequência esgotava o "Verificar agora". O aceite (passo 7) é a única rota com
        // `reauth`, e o `pentest.domain` dá o 404 do domínio alheio antes dela; ver o termo nunca pede.
        Route::middleware('pentest.available')->group(function () {
            // Sempre, com ou sem a liberação do admin: a entrada (que diz como pedir a liberação), o pedido, o
            // histórico e o texto aceito. Revogar e excluir ficam fora do `verified`, no grupo do Parar, acima.
            Route::get('/testes', ShowPentestController::class)->name('tests');
            Route::post('/testes/liberacao', RequestPentestAccessController::class)->middleware('throttle:5,1,pentest-liberacao')->name('tests.access.request');
            Route::get('/testes/dominios/{dominio}', ShowPentestDomainController::class)->name('tests.domains.show');
            Route::get('/testes/dominios/{dominio}/termo', ShowPentestConsentController::class)->name('tests.domains.consent');
            // Só a conta liberada pelo admin (e a do admin, para os domínios dela). Sem a liberação, voltam à
            // entrada sem executar nada, antes do domínio, da senha e do limite.
            Route::middleware('pentest.access')->group(function () {
                Route::get('/testes/autorizar/{passo}', ShowPentestStepController::class)->whereNumber('passo')->name('tests.authorize');
                Route::put('/testes/autorizar/{passo}', UpdatePentestStepController::class)->whereNumber('passo')->middleware('throttle:30,1,pentest-passos')->name('tests.authorize.update');
                Route::post('/testes/dominios', StorePentestDomainController::class)->middleware('throttle:10,1,pentest-dominios')->name('tests.domains.store');
                Route::post('/testes/dominios/{dominio}/verificar', VerifyPentestDomainController::class)->middleware('throttle:6,1,pentest-posse')->name('tests.domains.verify');
                Route::post('/testes/dominios/{dominio}/termo', AcceptPentestConsentController::class)->middleware(['pentest.domain:7', 'throttle:10,1,pentest-aceite'])->name('tests.domains.accept');
                // "Nova execução", com a reautenticação como o aceite; pausar e retomar, cada um com o seu limite. Retomar
                // também pede a reautenticação (volta a mandar tráfego), conferida no controller depois do 404 da execução.
                Route::post('/testes/dominios/{dominio}/execucoes', StartPentestRunController::class)->middleware(['pentest.domain', 'throttle:3,1,pentest-execucoes'])->name('tests.runs.store');
                Route::post('/testes/execucoes/{execucao}/pausar', PausePentestRunController::class)->whereUuid('execucao')->middleware('throttle:10,1,pentest-pausar')->name('tests.runs.pause');
                Route::post('/testes/execucoes/{execucao}/retomar', ResumePentestRunController::class)->whereUuid('execucao')->middleware('throttle:10,1,pentest-retomar')->name('tests.runs.resume');
            });
        });

        Route::get('/entrevista/{passo}', ShowInterviewStepController::class)->whereNumber('passo')->name('interview');
        Route::put('/entrevista/{passo}', UpdateInterviewStepController::class)->whereNumber('passo')->name('interview.update');
        Route::post('/entrevista/concluir', FinishInterviewController::class)->name('interview.finish');
    });
});

// "Relevante" e "Não é relevante" direto da mensagem do Slack ou do Discord: a assinatura substitui o login.
// Robô não abre (`robots.none`): a prévia do Slack votaria pelo cliente. Uso único: o primeiro voto
// vale e, depois dele, os dois links só mostram a resposta registrada (AlertFeedback).
Route::get('/a/{match}/nao-relevante', SignedIrrelevantController::class)
    ->whereNumber('match')
    ->middleware(['robots.none', 'signed', 'throttle:30,1', 'signed.active'])
    ->name('alerts.feedback.signed');
Route::get('/a/{match}/relevante', SignedRelevantController::class)
    ->whereNumber('match')
    ->middleware(['robots.none', 'signed', 'throttle:30,1', 'signed.active'])
    ->name('alerts.feedback.signed.relevant');
// "Ler no Security Monitor" da mensagem do Slack: leva à matéria, mas a prévia do Slack não monta cartão dela.
Route::get('/a/materia/{uuid}', ReadAlertCaseController::class)
    ->whereUuid('uuid')
    ->middleware('robots.none')
    ->name('alerts.read');
// O motivo escrito na página que esses links abrem (também assinado, sem login).
Route::post('/a/{match}/motivo', SignedFeedbackNoteController::class)
    ->whereNumber('match')
    ->middleware(['signed', 'throttle:20,1', 'signed.active'])
    ->name('alerts.feedback.signed.note');

// Radar de domínios pela mensagem do aviso: "É golpe", "É nosso", "Não é ameaça", o motivo, e a foto
// (que o Slack, o Discord e o Telegram buscam sem login). A assinatura substitui o login. Os botões
// são de uso único e robô não os abre (a prévia gastaria a resposta); a foto, sim, e quantas vezes quiser.
Route::get('/r/{lookalike}/{acao}', SignedLookalikeVerdictController::class)
    ->whereNumber('lookalike')->whereIn('acao', ['nosso', 'seguro', 'golpe'])
    ->middleware(['robots.none', 'signed', 'throttle:30,1', 'signed.active'])
    ->name('lookalikes.verdict.signed');
Route::post('/r/{lookalike}/motivo', SignedLookalikeNoteController::class)
    ->whereNumber('lookalike')
    ->middleware(['signed', 'throttle:20,1', 'signed.active'])
    ->name('lookalikes.verdict.signed.note');
Route::get('/r/foto/{alert}', ShowAlertPhotoController::class)
    ->whereNumber('alert')
    ->middleware(['signed', 'throttle:120,1', 'signed.active:image'])
    ->name('lookalikes.photo');

// Monitoramento do GitHub pela mensagem: "Relevante", "Não é relevante" e o motivo. A assinatura substitui o login.
// Uso único, e robô não abre (a prévia gastaria o voto).
Route::get('/g/{finding}/{acao}', SignedGithubVoteController::class)
    ->whereNumber('finding')->whereIn('acao', array_keys(GithubFeedback::ACTIONS))
    ->middleware(['robots.none', 'signed', 'throttle:30,1', 'signed.active'])
    ->name('github.feedback.signed');
Route::post('/g/{finding}/motivo', SignedGithubNoteController::class)
    ->whereNumber('finding')
    ->middleware(['signed', 'throttle:20,1', 'signed.active'])
    ->name('github.feedback.signed.note');

// Os limites do admin também têm contador próprio (ver o grupo com login acima).
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', ShowAdminDashboardController::class)->name('dashboard');

    Route::get('/config', ShowSettingsController::class)->name('settings');
    Route::put('/config', UpdateSettingsController::class)->name('settings.update');

    Route::get('/fontes', ListSourcesController::class)->name('sources');
    Route::post('/fontes', StoreSourceController::class)->name('sources.store');
    Route::put('/fontes/{source}', UpdateSourceController::class)->name('sources.update');
    Route::post('/fontes/{source}/toggle', ToggleSourceController::class)->name('sources.toggle');
    Route::post('/fontes/{source}/rodar', RunSourceController::class)->name('sources.run');
    Route::delete('/fontes/{source}', DestroySourceController::class)->name('sources.destroy');

    Route::post('/bots/rodar', RunAllSourcesController::class)->name('bots.run');
    Route::post('/indice/reindexar', ReindexCasesController::class)->name('reindex');

    Route::get('/coletas', ShowCollectionsController::class)->name('collections');
    // Fila: os jobs esperando ou rodando e os que falharam, com o motivo (os cartões do painel levam aqui).
    Route::get('/fila', ShowQueueController::class)->name('queue');
    // Adiantar o agendado: o `available_at` vira agora (só o que nenhum worker pegou e ainda tem hora no futuro).
    Route::post('/fila/adiantar', AdvanceScheduledJobsController::class)->middleware('throttle:6,1,fila-adiantar-todos')->name('queue.advance-all');
    Route::post('/fila/{job}/adiantar', AdvanceJobController::class)->whereNumber('job')->middleware('throttle:30,1,fila-adiantar')->name('queue.advance');
    Route::post('/fila/falhos/retentar', RetryFailedJobsController::class)->name('queue.retry-all');
    Route::delete('/fila/falhos', DestroyFailedJobsController::class)->name('queue.forget-all');
    Route::post('/fila/falhos/{uuid}/retentar', RetryFailedJobController::class)->whereUuid('uuid')->name('queue.retry');
    Route::delete('/fila/falhos/{uuid}', DestroyFailedJobController::class)->whereUuid('uuid')->name('queue.forget');
    // As matérias que ficaram sem a IA (ela falhou e entrou a heurística), refeitas no lugar.
    Route::post('/coletas/refazer-com-ia', RebuildWithoutAiController::class)->middleware('throttle:6,1,coletas-refazer-ia')->name('articles.rebuild-ai');
    Route::get('/execucoes', ListCrawlRunsController::class)->name('runs');
    Route::post('/artigos/{article}/retentar', RetryArticleController::class)->name('articles.retry');
    Route::get('/artigos/{article}/texto', EditArticleTextController::class)->name('articles.text');
    Route::put('/artigos/{article}/texto', UpdateArticleTextController::class)->name('articles.text.update');
    Route::delete('/casos/{case:uuid}', DestroyCaseController::class)->name('cases.destroy');
    // Fora do feed público (fora de segurança), sem apagar; o mesmo botão devolve. Fica na página da matéria.
    Route::post('/casos/{case:uuid}/visibilidade', ToggleCaseVisibilityController::class)->name('cases.visibility');

    Route::prefix('alertas')->name('alerts.')->group(function () {
        Route::get('/', ShowAlertsOverviewController::class)->name('overview');
        Route::put('/config', UpdateAlertsConfigController::class)->name('config');
        Route::post('/rodar-match', RunAlertsMatchController::class)->name('run-match');
        Route::post('/enviar-resumos', SendDigestsController::class)->name('send-digests');
        Route::post('/recompilar', RecompileProfilesController::class)->name('recompile');

        Route::get('/clientes', ListClientsController::class)->name('clients');
        Route::get('/clientes/{user}', ShowClientController::class)->name('client');
        Route::post('/clientes/{user}/pausar', ToggleClientPauseController::class)->name('client.pause');
        Route::post('/clientes/{user}/teste', TestClientController::class)->middleware('throttle:10,1,admin-cliente-teste')->name('client.test');
        Route::post('/clientes/{user}/recompilar', RecompileClientController::class)->name('client.recompile');
        Route::post('/clientes/{user}/tecnologia', UpdateClientTechController::class)->name('client.tech');

        Route::get('/entregas', ListDeliveriesController::class)->name('deliveries');
        Route::post('/entregas/{delivery}/reenviar', RetryDeliveryController::class)->name('deliveries.retry');

        Route::get('/catalogo', ShowCatalogController::class)->name('catalog');
        Route::post('/catalogo', StoreTechController::class)->name('catalog.store');
        Route::put('/catalogo/{tech}', UpdateTechController::class)->name('catalog.update');
        Route::post('/catalogo/{tech}/alternar', ToggleTechController::class)->name('catalog.toggle');

        Route::get('/respostas-escritas', ListCustomAnswersController::class)->name('custom');
        Route::put('/respostas-escritas/{answer}', UpdateCustomAnswerController::class)->name('custom.update');
        Route::post('/respostas-escritas/{answer}/gerar', RegenerateCustomAnswerController::class)->name('custom.regenerate');

        Route::get('/perguntas', ListQuestionsController::class)->name('questions');
        Route::post('/perguntas', StoreQuestionController::class)->name('questions.store');
        Route::put('/perguntas/{question}', UpdateQuestionController::class)->name('questions.update');
        Route::post('/perguntas/{question}/mover', MoveQuestionController::class)->name('questions.move');
        Route::delete('/perguntas/{question}', DestroyQuestionController::class)->name('questions.destroy');

        Route::get('/dominios', ListDomainsController::class)->name('domains');
        Route::post('/dominios/{domain}/rodar', RunDomainDiscoveryController::class)->name('domains.run');
    });

    Route::prefix('prompts')->name('prompts.')->group(function () {
        // Só etapas e trechos do catálogo; o resto dá 404. O ponto do nome do trecho não vale como curinga.
        $parts = array_map('preg_quote', array_keys(PromptCatalog::PARTS));

        Route::get('/', ListPromptsController::class)->name('index');
        Route::get('/{stage}', ShowPromptStageController::class)->whereIn('stage', array_keys(PromptCatalog::STAGES))->name('show');
        Route::post('/{stage}/alternar', ToggleStageController::class)->whereIn('stage', array_keys(PromptCatalog::STAGES))->name('toggle');
        Route::put('/trechos/{part}', UpdatePromptController::class)->whereIn('part', $parts)->name('update');
        Route::post('/trechos/{part}/padrao', ResetPromptController::class)->whereIn('part', $parts)->name('reset');
        Route::post('/revisoes/{revision}/restaurar', RestorePromptRevisionController::class)->name('restore');
    });

    // Diário de bordo da /sobre: criar, editar e remover os registros.
    Route::get('/diario-de-bordo', ListLogEntriesController::class)->name('logbook');
    Route::post('/diario-de-bordo', StoreLogEntryController::class)->name('logbook.store');
    Route::put('/diario-de-bordo/{entry}', UpdateLogEntryController::class)->name('logbook.update');
    Route::delete('/diario-de-bordo/{entry}', DestroyLogEntryController::class)->name('logbook.destroy');

    // Acessos: quem abre o site (IP, país, navegador, conta), quantas vezes, que página e por quanto tempo.
    // Em abas, com os mesmos filtros: a visão geral, os robôs e de onde vêm as pessoas.
    Route::get('/acessos', ShowAccessesController::class)->name('accesses');
    Route::get('/acessos/robos', ShowAccessRobotsController::class)->name('accesses.robots');
    Route::get('/acessos/origens', ShowAccessOriginsController::class)->name('accesses.origins');
    Route::get('/acessos/ataques', ShowAccessAttacksController::class)->name('accesses.attacks');
    Route::get('/acessos/por-ip', ShowAccessIpController::class)->name('accesses.ip');
    // Ajustes: a chave da monitoria e a lista de IPs nossos.
    Route::get('/acessos/ajustes', ShowAccessSettingsController::class)->name('accesses.settings');
    Route::post('/acessos/alternar', ToggleAccessMonitoringController::class)->name('accesses.toggle');
    // Os IPs "nossos" (o deploy, os scripts, quem cuida do site): a lista da tela e o "Adicionar o meu IP atual".
    Route::put('/acessos/nossos', UpdateOwnAddressesController::class)->name('accesses.own');
    Route::post('/acessos/nossos/meu-ip', AddMyAddressController::class)->name('accesses.own.mine');
    // "Contar como nosso", na ficha do IP.
    Route::post('/acessos/nossos/ip', AddOwnAddressController::class)->name('accesses.own.add');
    // Apagar um acesso, ou todos os de um IP (o período inteiro), na ficha do IP.
    Route::delete('/acessos/ip', DestroyAccessesByIpController::class)->name('accesses.destroy-ip');
    Route::delete('/acessos/{view}', DestroyAccessController::class)->whereNumber('view')->name('accesses.destroy');

    // E-mails: se o envio está de pé, o que saiu e o que deu erro, e o envio de teste.
    Route::get('/emails', ShowEmailsController::class)->name('emails');
    Route::post('/emails/teste', SendTestEmailController::class)->middleware('throttle:5,1,email-teste')->name('emails.test');

    // Monitoria de segurança dos sites do admin: só leitura (GET, DNS e TLS) e só em site cadastrado.
    Route::get('/seguranca', ListSecuritySitesController::class)->name('security');
    Route::post('/seguranca', StoreSecuritySiteController::class)->middleware('throttle:20,1,admin-seguranca-novo')->name('security.store');
    Route::get('/seguranca/{site}', ShowSecuritySiteController::class)->whereNumber('site')->name('security.show');
    Route::post('/seguranca/{site}/verificar', RunSecurityScanController::class)->whereNumber('site')->middleware('throttle:20,1,admin-seguranca-verificar')->name('security.run');
    Route::post('/seguranca/{site}/posse', RecheckSecurityOwnershipController::class)->whereNumber('site')->middleware('throttle:20,1,admin-seguranca-posse')->name('security.ownership');
    Route::post('/seguranca/{site}/pausar', ToggleSecuritySiteController::class)->whereNumber('site')->name('security.toggle');
    Route::delete('/seguranca/{site}', DestroySecuritySiteController::class)->whereNumber('site')->name('security.destroy');

    // Usuários: a lista, nova senha, remoção da conta e a liberação dos testes de segurança. A tela era
    // "Convites e usuários" em /convites até o cadastro abrir; o endereço antigo (favoritos) leva para cá.
    // Só o GET: o POST e o DELETE dos convites deixaram de existir.
    Route::get('/usuarios', ListUsersController::class)->name('users');
    Route::get('/convites', RedirectController::class)->defaults('destination', '/admin/usuarios')->defaults('status', 301)->name('users.legacy');
    Route::delete('/usuarios/{user}', DestroyUserController::class)->name('users.destroy');
    Route::post('/usuarios/{user}/testes', GrantPentestAccessController::class)->whereNumber('user')->middleware('throttle:30,1,admin-usuario-testes')->name('users.pentest.grant');
    Route::delete('/usuarios/{user}/testes', RevokePentestAccessController::class)->whereNumber('user')->name('users.pentest.revoke');

    // Testes ativos (P6b): a chave e o "Parar tudo", as aprovações e as execuções de todas as contas. Desligar, "Parar
    // tudo" e o Parar de uma execução não têm limite de requisições: parar nunca é recusado.
    Route::prefix('testes')->name('pentest.')->group(function () {
        Route::get('/', ShowPentestOverviewController::class)->name('overview');
        Route::post('/ligar', EnablePentestController::class)->middleware('throttle:10,1,admin-testes-ligar')->name('enable');
        Route::post('/desligar', DisablePentestController::class)->name('disable');
        Route::post('/parar-tudo', StopAllPentestController::class)->name('stop-all');
        Route::get('/aprovacoes', ListPentestApprovalsController::class)->name('approvals');
        Route::get('/aprovacoes/{aprovacao}', ShowPentestApprovalController::class)->whereNumber('aprovacao')->name('approvals.show');
        Route::post('/aprovacoes/{aprovacao}/aprovar', ApprovePentestRunController::class)->whereNumber('aprovacao')->middleware('throttle:30,1,admin-testes-aprovar')->name('approvals.approve');
        Route::post('/aprovacoes/{aprovacao}/recusar', RejectPentestRunController::class)->whereNumber('aprovacao')->middleware('throttle:30,1,admin-testes-recusar')->name('approvals.reject');
        Route::get('/execucoes', ListAdminPentestRunsController::class)->name('runs');
        Route::get('/execucoes/{execucao}', ShowAdminPentestRunController::class)->whereUuid('execucao')->name('runs.show');
        Route::post('/execucoes/{execucao}/parar', StopAdminPentestRunController::class)->whereUuid('execucao')->name('runs.stop');
        Route::post('/execucoes/{execucao}/pausar', PauseAdminPentestRunController::class)->whereUuid('execucao')->middleware('throttle:30,1,admin-testes-pausar')->name('runs.pause');
        Route::post('/execucoes/{execucao}/retomar', ResumeAdminPentestRunController::class)->whereUuid('execucao')->middleware('throttle:30,1,admin-testes-retomar')->name('runs.resume');
        Route::post('/execucoes/{execucao}/reteste', AllowPentestRetestController::class)->whereUuid('execucao')->middleware('throttle:30,1,admin-testes-reteste')->name('runs.retest');
    });
});
