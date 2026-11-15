<?php

use App\Http\Controllers\Account\CancelTwoFactorSetupController;
use App\Http\Controllers\Account\ConfirmReauthCodeController;
use App\Http\Controllers\Account\ConfirmReauthTwoFactorController;
use App\Http\Controllers\Account\ConfirmTwoFactorController;
use App\Http\Controllers\Account\DisableTwoFactorController;
use App\Http\Controllers\Account\GithubReauthCallbackController;
use App\Http\Controllers\Account\RegenerateRecoveryCodesController;
use App\Http\Controllers\Account\SendReauthCodeController;
use App\Http\Controllers\Account\ShowAccountController;
use App\Http\Controllers\Account\ShowTwoFactorController;
use App\Http\Controllers\Account\StartGithubReauthController;
use App\Http\Controllers\Account\StartTwoFactorController;
use App\Http\Controllers\Account\UpdateAccountController;
use App\Http\Controllers\Account\UpdatePasswordController;
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
use App\Http\Controllers\Admin\Companies\DisableCompanyController;
use App\Http\Controllers\Admin\Companies\EnableCompanyController;
use App\Http\Controllers\Admin\Companies\ListCompaniesController;
use App\Http\Controllers\Admin\Companies\ResendCompanyEntryController;
use App\Http\Controllers\Admin\Companies\ResumeCompanyController;
use App\Http\Controllers\Admin\Companies\StoreCompanyController;
use App\Http\Controllers\Admin\Companies\SuspendCompanyController;
use App\Http\Controllers\Admin\Companies\UpdateCompanyLimitsController;
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
use App\Http\Controllers\Admin\Users\ResetUserPasswordController;
use App\Http\Controllers\Admin\Users\RevokePentestAccessController;
use App\Http\Controllers\Alerts\Center\ToggleChannelPauseController;
use App\Http\Controllers\Alerts\Center\UpdateAlertCenterController;
use App\Http\Controllers\Alerts\ChangeAlertSectorController;
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
use App\Http\Controllers\Alerts\Github\ChangeGithubSectorController;
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
use App\Http\Controllers\Alerts\Lookalikes\ChangeRadarSectorController;
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
use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Auth\ConfirmTwoFactorChallengeController;
use App\Http\Controllers\Auth\GithubCallbackController;
use App\Http\Controllers\Auth\GithubLoginController;
use App\Http\Controllers\Auth\GithubRegisterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResendVerifyEmailController;
use App\Http\Controllers\Auth\ShowAdminLoginController;
use App\Http\Controllers\Auth\ShowLoginController;
use App\Http\Controllers\Auth\ShowRegisterController;
use App\Http\Controllers\Auth\ShowTwoFactorChallengeController;
use App\Http\Controllers\Auth\ShowVerifyEmailController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Company\AcceptCompanyInviteController;
use App\Http\Controllers\Company\AddCompanySectorMemberController;
use App\Http\Controllers\Company\CancelCompanyInviteController;
use App\Http\Controllers\Company\ChangeCompanyDomainSectorController;
use App\Http\Controllers\Company\CompleteCompanyEntryController;
use App\Http\Controllers\Company\DeactivateCompanyMemberController;
use App\Http\Controllers\Company\DeleteCompanySectorController;
use App\Http\Controllers\Company\ExportCompanyActivityController;
use App\Http\Controllers\Company\ListCompanyAccountsController;
use App\Http\Controllers\Company\ListCompanyActivityController;
use App\Http\Controllers\Company\ListCompanyDomainsController;
use App\Http\Controllers\Company\ListCompanySectorsController;
use App\Http\Controllers\Company\ReactivateCompanyMemberController;
use App\Http\Controllers\Company\RemoveCompanyDomainController;
use App\Http\Controllers\Company\RemoveCompanySectorMemberController;
use App\Http\Controllers\Company\RemoveCompanySubdomainController;
use App\Http\Controllers\Company\RenameCompanySectorController;
use App\Http\Controllers\Company\ResendCompanyInviteController;
use App\Http\Controllers\Company\ResetCompanyMemberTwoFactorController;
use App\Http\Controllers\Company\ShowCompanyEntryController;
use App\Http\Controllers\Company\ShowCompanyInviteController;
use App\Http\Controllers\Company\StoreCompanyDomainController;
use App\Http\Controllers\Company\StoreCompanyInviteController;
use App\Http\Controllers\Company\StoreCompanySectorController;
use App\Http\Controllers\Company\StoreCompanySubdomainController;
use App\Http\Controllers\Company\TransferCompanyAdminController;
use App\Http\Controllers\Company\UpdateCompanyMemberRoleController;
use App\Http\Controllers\Company\UpdateCompanySectorManagerController;
use App\Http\Controllers\Company\UpdateCompanyTwoFactorController;
use App\Http\Controllers\Company\VerifyCompanyDomainController;
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

// Usuário comum: cadastro aberto (captcha e e-mail confirmado) e só mexe nos próprios dados.
// Sem login, a chave do limite é o IP. Dois contadores (item 10 do Blue Team): entrar e cadastrar dividem
// o `entrada`, e as 3 rotas do GitHub dividem o `github-oauth`. Assim, o abuso do login numa rede (NAT)
// não tranca o login com o GitHub, e quem distribui entre as rotas não ganha um contador por rota.
Route::middleware('guest')->group(function () {
    Route::get('/login', ShowLoginController::class)->name('login');
    Route::post('/login', LoginController::class)->middleware('throttle:10,1,entrada');
    Route::get('/cadastro', ShowRegisterController::class)->name('register');
    Route::post('/cadastro', RegisterController::class)->middleware('throttle:10,1,entrada');

    // Entrar com o GitHub: a mesma volta serve ao login e ao cadastro (a sessão sabe qual).
    Route::get('/login/github', GithubLoginController::class)->middleware('throttle:10,1,github-oauth')->name('login.github');
    Route::get('/login/github/callback', GithubCallbackController::class)->middleware('throttle:10,1,github-oauth')->name('login.github.callback');
    Route::post('/cadastro/github', GithubRegisterController::class)->middleware('throttle:10,1,github-oauth')->name('register.github');

    // O código da 2FA, depois da senha certa ou da volta do GitHub (TwoFactorChallenge, com o limite por conta e por IP).
    Route::middleware('two-factor.available')->group(function () {
        Route::get('/login/codigo', ShowTwoFactorChallengeController::class)->name('login.two-factor');
        Route::post('/login/codigo', ConfirmTwoFactorChallengeController::class)->middleware('throttle:10,1,login-codigo')->name('login.two-factor.confirm');
    });
});

// Plano empresarial: o link da admin da empresa para escolher a senha (CompanyEntry). A assinatura (7 dias) vem antes
// da conta; o link usado, vencido ou de empresa inativa é 404. Sem ENTERPRISE_ENABLED, 404.
Route::middleware(['guest', 'enterprise', 'signed'])->prefix('empresa/entrar')->name('company.')->group(function () {
    Route::get('/{user}', ShowCompanyEntryController::class)->whereNumber('user')->middleware('throttle:20,1,empresa-entrada')->name('entry');
    Route::post('/{user}', CompleteCompanyEntryController::class)->whereNumber('user')->middleware('throttle:10,1,empresa-entrada-senha')->name('entry.complete');
});

// O convite da empresa (CompanyInvites): o token (só o hash fica no banco) substitui o login. Convite usado, cancelado,
// vencido ou de empresa inativa cai na mesma página amigável. Sem ENTERPRISE_ENABLED, 404.
Route::middleware(['guest', 'enterprise'])->prefix('empresa/convite')->name('company.')->group(function () {
    Route::get('/{token}', ShowCompanyInviteController::class)->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:20,1,empresa-convite')->name('invite');
    Route::post('/{token}', AcceptCompanyInviteController::class)->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1,empresa-convite-aceitar')->name('invite.accept');
});

// O link do e-mail de confirmação: a assinatura (vale 1 h) substitui o login, então abre em qualquer aparelho.
// Ela é conferida antes de a conta ser procurada; o hash é do e-mail atual (o link de um e-mail trocado é 404).
Route::get('/conta/confirmar-email/{user}/{hash}', VerifyEmailController::class)
    ->whereNumber('user')
    ->middleware(['signed', 'throttle:6,1,email-confirmar'])
    ->name('verification.verify');

// Com login, cada limite (`throttle:N,M`) tem o seu contador, no 3º parâmetro. Sem ele, a chave do limite
// é só a conta: todos os limites dela somam no mesmo contador, e gravar a entrevista do Radar (30/min)
// esgotava o "Verificar agora" (6/min). O RouteTableTest cobra o contador próprio em toda rota com login.
// `two-factor.required`: o admin com TWO_FACTOR_ADMIN_REQUIRED e sem a 2FA só chega à tela de configurar (I1 do Red Team).
// Ele roda depois do EnsureCompanyAccountActive (no grupo `web`): a conta de empresa inativa cai antes e nunca chega à tela de configurar.
Route::middleware(['auth', 'two-factor.required'])->group(function () {
    Route::get('/conta', ShowAccountController::class)->name('account.show');

    // Atividade da empresa (§4.9): a auditoria, com filtros e CSV; só quem administra.
    Route::middleware(['enterprise', 'company.admin'])->prefix('empresa/atividade')->name('company.')->group(function () {
        Route::get('/', ListCompanyActivityController::class)->name('activity');
        Route::get('/csv', ExportCompanyActivityController::class)->middleware('throttle:10,1,empresa-atividade-csv')->name('activity.csv');
    });

    // Setores da empresa (E2a): só quem administra a empresa; os outros levam 404 antes de procurar o setor da URL.
    Route::middleware(['enterprise', 'company.admin'])->prefix('empresa/setores')->name('company.')->group(function () {
        Route::get('/', ListCompanySectorsController::class)->name('sectors');
        Route::post('/', StoreCompanySectorController::class)->middleware('throttle:20,1,empresa-setor-criar')->name('sectors.store');
        // Apagar (E2f, §4.13): o que era do setor fica sem setor, as contas saem; com a reautenticação.
        Route::delete('/', DeleteCompanySectorController::class)->middleware(['throttle:10,1,empresa-setor-apagar', 'reauth'])->name('sectors.delete');
        Route::put('/{sector}', RenameCompanySectorController::class)->whereNumber('sector')->middleware('throttle:30,1,empresa-setor-renomear')->name('sectors.rename');
        Route::post('/{sector}/contas', AddCompanySectorMemberController::class)->whereNumber('sector')->middleware('throttle:60,1,empresa-setor-por')->name('sectors.members.add');
        Route::delete('/{sector}/contas/{member}', RemoveCompanySectorMemberController::class)->whereNumber(['sector', 'member'])->middleware('throttle:60,1,empresa-setor-tirar')->name('sectors.members.remove');
        Route::put('/{sector}/contas/{member}/responsavel', UpdateCompanySectorManagerController::class)->whereNumber(['sector', 'member'])->middleware('throttle:60,1,empresa-setor-responsavel')->name('sectors.manager');
    });

    // Domínios da empresa (E3a3, DESENHO-5.3 §2.7): toda conta da empresa vê os domínios do que vê; quem configura
    // (a admin, o responsável nos setores dele) cadastra, comprova, troca o setor e remove. A conta pessoal leva 404.
    // Remover leva o domínio no corpo, como apagar setor: a reautenticação vem antes e não diz se o id existe.
    Route::middleware(['enterprise', 'company.account'])->prefix('empresa/dominios')->name('company.')->group(function () {
        Route::get('/', ListCompanyDomainsController::class)->name('domains');
        Route::post('/', StoreCompanyDomainController::class)->middleware('throttle:20,1,empresa-dominio-criar')->name('domains.store');
        Route::delete('/', RemoveCompanyDomainController::class)->middleware(['throttle:10,1,empresa-dominio-remover', 'reauth'])->name('domains.remove');
        Route::post('/{domain}/verificar', VerifyCompanyDomainController::class)->whereNumber('domain')->middleware('throttle:6,1,empresa-dominio-verificar')->name('domains.verify');
        Route::put('/{domain}/setor', ChangeCompanyDomainSectorController::class)->whereNumber('domain')->middleware('throttle:20,1,empresa-dominio-setor')->name('domains.sector');
        // Subdomínios do domínio (E3b, R6): quem vê o domínio chega à rota (o resto, 404); quem cadastra e tira (a admin,
        // quem responde pelo setor do domínio) é conferido no CompanySubdomains.
        Route::post('/{domain}/subdominios', StoreCompanySubdomainController::class)->whereNumber('domain')->middleware('throttle:30,1,empresa-subdominio')->name('subdomains.store');
    });
    Route::middleware(['enterprise', 'company.account'])->prefix('empresa/subdominios')->name('company.')->group(function () {
        Route::delete('/{subdomain}', RemoveCompanySubdomainController::class)->whereNumber('subdomain')->middleware('throttle:30,1,empresa-subdominio-tirar')->name('subdomains.remove');
    });

    // Contas da empresa (E1c): só quem administra a empresa; os outros levam 404. Sem ENTERPRISE_ENABLED, 404.
    Route::middleware(['enterprise', 'company.admin'])->prefix('empresa/contas')->name('company.')->group(function () {
        Route::get('/', ListCompanyAccountsController::class)->name('accounts');
        Route::post('/convites', StoreCompanyInviteController::class)->middleware('throttle:20,1,empresa-convidar')->name('invites.store');
        Route::post('/convites/{invite}/reenviar', ResendCompanyInviteController::class)->whereNumber('invite')->middleware('throttle:10,1,empresa-convite-reenviar')->name('invites.resend');
        Route::delete('/convites/{invite}', CancelCompanyInviteController::class)->whereNumber('invite')->name('invites.cancel');
        // Papéis (§4.3): o de membro e responsável aqui; a administração passa com a reautenticação.
        Route::put('/{member}/papel', UpdateCompanyMemberRoleController::class)->whereNumber('member')->middleware('throttle:30,1,empresa-papel')->name('members.role');
        // Remover (§4.6): a conta fica desativada, com a reautenticação; reativar confere a vaga.
        Route::post('/{member}/remover', DeactivateCompanyMemberController::class)->whereNumber('member')->middleware(['reauth', 'throttle:10,1,empresa-remover'])->name('members.deactivate');
        Route::post('/{member}/reativar', ReactivateCompanyMemberController::class)->whereNumber('member')->middleware(['reauth', 'throttle:10,1,empresa-reativar'])->name('members.reactivate');
        Route::post('/administracao', TransferCompanyAdminController::class)->middleware(['reauth', 'throttle:5,1,empresa-administracao'])->name('admin.transfer');
        // A 2FA da empresa (E1f): exigir de todas as contas e redefinir a de quem perdeu o celular (D11), com a reautenticação.
        Route::post('/duas-etapas', UpdateCompanyTwoFactorController::class)->middleware(['throttle:10,1,empresa-2fa', 'reauth'])->name('two-factor.update');
        Route::post('/duas-etapas/redefinir', ResetCompanyMemberTwoFactorController::class)->middleware(['throttle:10,1,empresa-2fa-redefinir', 'reauth'])->name('two-factor.reset');
    });
    Route::put('/conta', UpdateAccountController::class)->name('account.update');
    Route::put('/conta/senha', UpdatePasswordController::class)->name('account.password');
    Route::post('/logout', LogoutController::class)->name('logout');

    // Confirmação do e-mail: o aviso e o pedido de outro link (o link em si fica fora do login, acima).
    // Os alertas pedem o e-mail confirmado (`verified` no grupo abaixo); a conta não.
    Route::get('/conta/confirmar-email', ShowVerifyEmailController::class)->name('verification.notice');
    Route::post('/conta/confirmar-email/reenviar', ResendVerifyEmailController::class)->middleware('throttle:3,10,email-reenviar')->name('verification.send');

    // Confirmar que é você (reautenticação recente) pelo GitHub ou por um código num app; a senha
    // vai no formulário da própria ação. A volta do GitHub é um subcaminho do callback do login, o
    // que o OAuth App aceita como redirect_uri, mas fica aqui, só para quem está logado.
    Route::post('/conta/reautenticar/github', StartGithubReauthController::class)->middleware('throttle:10,1,reauth-github')->name('account.reauth.github');
    Route::get('/login/github/callback/reautenticar', GithubReauthCallbackController::class)->middleware('throttle:10,1,reauth-github-volta')->name('account.reauth.github.callback');
    Route::post('/conta/reautenticar/codigo', SendReauthCodeController::class)->middleware('throttle:6,1,reauth-codigo')->name('account.reauth.code');
    Route::post('/conta/reautenticar/codigo/confirmar', ConfirmReauthCodeController::class)->middleware('throttle:10,1,reauth-codigo-confirmar')->name('account.reauth.code.confirm');
    // Com a 2FA, o código do app depois de qualquer uma das três (o limite de erros é o mesmo do login).
    Route::post('/conta/reautenticar/duas-etapas', ConfirmReauthTwoFactorController::class)->middleware(['two-factor.available', 'throttle:10,1,reauth-duas-etapas'])->name('account.reauth.two-factor');

    // Verificação em duas etapas (app autenticador): só com TWO_FACTOR_ENABLED (senão, 404, antes do
    // limite e da senha). Ver a tela é livre; ligar, confirmar, gerar códigos novos e desligar pedem a
    // reautenticação: com só o cookie da sessão, ninguém liga a 2FA com o próprio app (trancando a dona
    // da conta para fora), vê códigos de recuperação nem desliga a 2FA.
    Route::middleware('two-factor.available')->group(function () {
        Route::get('/conta/duas-etapas', ShowTwoFactorController::class)->name('account.two-factor');
        Route::post('/conta/duas-etapas', StartTwoFactorController::class)->middleware(['throttle:10,1,duas-etapas-ligar', 'reauth'])->name('account.two-factor.start');
        Route::post('/conta/duas-etapas/confirmar', ConfirmTwoFactorController::class)->middleware(['throttle:6,1,duas-etapas-confirmar', 'reauth'])->name('account.two-factor.confirm');
        Route::post('/conta/duas-etapas/codigos', RegenerateRecoveryCodesController::class)->middleware(['throttle:5,1,duas-etapas-codigos', 'reauth'])->name('account.two-factor.codes');
        Route::delete('/conta/duas-etapas', DisableTwoFactorController::class)->middleware(['throttle:5,1,duas-etapas-desligar', 'reauth'])->name('account.two-factor.disable');
        // Cancelar a configuração ainda não confirmada não diminui a proteção: sem a senha.
        Route::delete('/conta/duas-etapas/configuracao', CancelTwoFactorSetupController::class)->middleware('throttle:10,1,duas-etapas-cancelar')->name('account.two-factor.cancel');
    });

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
    Route::prefix('alertas')->name('alerts.')->middleware('verified')->group(function () {
        Route::get('/', ShowAlertsController::class)->name('index');
        Route::get('/integracao', ShowIntegrationsController::class)->name('integrations');

        // Notícias: um alerta por produto ou site, cada um com a sua entrevista (a de sempre, em /alertas/entrevista).
        Route::get('/noticias', ShowNewsController::class)->name('news');
        Route::post('/novo', StoreAlertController::class)->middleware('throttle:10,1,alerta-novo')->name('store');
        Route::post('/pausar', ToggleAlertPauseController::class)->name('pause');
        // O setor do alerta de empresa (E2c2): quem o configura troca, com a Atividade registrando.
        Route::put('/setor', ChangeAlertSectorController::class)->middleware('throttle:20,1,alerta-setor')->name('sector');
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
        // O setor do Radar de empresa (E2d1): quem o configura troca, com a Atividade registrando.
        Route::put('/radar-de-dominios/setor', ChangeRadarSectorController::class)->middleware('throttle:20,1,radar-setor')->name('lookalikes.sector');
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
        // O setor do monitoramento de empresa (E2d2): quem o configura troca, com a Atividade registrando.
        Route::put('/github/setor', ChangeGithubSectorController::class)->middleware('throttle:20,1,github-setor')->name('github.sector');
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
                Route::post('/testes/dominios/{dominio}/termo', AcceptPentestConsentController::class)->middleware(['pentest.domain:7', 'reauth:dominio', 'throttle:10,1,pentest-aceite'])->name('tests.domains.accept');
                // "Nova execução", com a reautenticação como o aceite; pausar e retomar, cada um com o seu limite. Retomar
                // também pede a reautenticação (volta a mandar tráfego), conferida no controller depois do 404 da execução.
                Route::post('/testes/dominios/{dominio}/execucoes', StartPentestRunController::class)->middleware(['pentest.domain', 'reauth:dominio', 'throttle:3,1,pentest-execucoes'])->name('tests.runs.store');
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

Route::middleware('guest')->prefix('admin')->group(function () {
    Route::get('/login', ShowAdminLoginController::class)->name('admin.login');
    Route::post('/login', AdminLoginController::class)->middleware('throttle:10,1');
});

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
    Route::post('/usuarios/{user}/senha', ResetUserPasswordController::class)->middleware('throttle:20,1,admin-usuario-senha')->name('users.password');
    Route::delete('/usuarios/{user}', DestroyUserController::class)->name('users.destroy');
    Route::post('/usuarios/{user}/testes', GrantPentestAccessController::class)->whereNumber('user')->middleware('throttle:30,1,admin-usuario-testes')->name('users.pentest.grant');
    Route::delete('/usuarios/{user}/testes', RevokePentestAccessController::class)->whereNumber('user')->name('users.pentest.revoke');

    // Plano empresarial (§4.11): as empresas, o uso contra os limites, os limites de cada uma, a suspensão (D7) e a
    // chave da empresa (D12). Só o admin do Security Monitor cria empresa (D6); sem ENTERPRISE_ENABLED, tudo 404.
    Route::middleware('enterprise')->prefix('empresas')->name('companies.')->group(function () {
        Route::get('/', ListCompaniesController::class)->name('index');
        Route::post('/', StoreCompanyController::class)->middleware('throttle:10,1,admin-empresa-nova')->name('store');
        Route::put('/{company}/limites', UpdateCompanyLimitsController::class)->whereNumber('company')->name('limits');
        Route::post('/{company}/suspender', SuspendCompanyController::class)->whereNumber('company')->name('suspend');
        Route::post('/{company}/reativar', ResumeCompanyController::class)->whereNumber('company')->name('resume');
        Route::post('/{company}/ligar', EnableCompanyController::class)->whereNumber('company')->name('enable');
        Route::post('/{company}/desligar', DisableCompanyController::class)->whereNumber('company')->name('disable');
        Route::post('/{company}/link', ResendCompanyEntryController::class)->whereNumber('company')->middleware('throttle:10,1,admin-empresa-link')->name('entry');
    });

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
