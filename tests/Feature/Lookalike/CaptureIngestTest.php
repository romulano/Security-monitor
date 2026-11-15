<?php

namespace Tests\Feature\Lookalike;

use App\Models\LookalikeAlert;
use App\Models\LookalikeCapture;
use App\Services\Lookalike\CaptureIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Resposta do navegador → prints por aparelho, situação da página, sinais, nível e aviso. */
class CaptureIngestTest extends TestCase
{
    use LookalikeTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTempBrowserDir();
    }

    protected function tearDown(): void
    {
        $this->tearDownBrowserDir();
        parent::tearDown();
    }

    /** Resposta completa: o resumo do domínio e os 3 aparelhos (todos iguais, salvo o que vier em $devices). */
    private function answer(array $top = [], array $devices = []): array
    {
        $base = $this->seen($top['desktop'] ?? []);
        unset($top['desktop']);

        return array_replace_recursive([
            'ok' => true,
            'status' => 200,
            'final_url' => 'https://acmepay-login.com/',
            'title' => $base['title'],
            'text' => $base['text'],
            'text_length' => $base['text_length'],
            'images' => $base['images'],
            'forms' => $base['forms'],
            'own_requests' => [],
            'resource_hosts' => ['acmepay-login.com'],
            'link_hosts' => [],
            'devices' => [
                'desktop' => $base,
                'android' => $this->seen($devices['android'] ?? []),
                'iphone' => $this->seen($devices['iphone'] ?? []),
            ],
        ], $top);
    }

    public function test_clone_asking_for_password_is_critical_keeps_prints_per_device_and_creates_the_alert(): void
    {
        $lookalike = $this->lookalike($this->watch());
        $capture = $this->browserAnswer($lookalike, $this->answer([
            'title' => 'Acme Pay | Entrar na sua conta',
            'forms' => ['inputs' => 2, 'password' => 1, 'email' => 1],
            'own_requests' => [
                ['url' => 'https://www.acmepay.com.br/logo.svg', 'type' => 'image', 'navigation' => false],
                ['url' => 'https://www.acmepay.com.br/app.css', 'type' => 'stylesheet', 'navigation' => false],
            ],
            'desktop' => ['title' => 'Acme Pay | Entrar na sua conta', 'forms' => ['inputs' => 2, 'password' => 1], 'own_requests' => 2],
        ]));

        $result = app(CaptureIngestor::class)->ingest();

        $this->assertSame(1, $result['captures']);
        $this->assertSame(1, $result['alerts']);
        $lookalike->refresh();
        $this->assertSame('content', $lookalike->state);
        $this->assertSame('critical', $lookalike->risk);
        $keys = array_column($lookalike->signals, 'key');
        $this->assertContains('hotlink', $keys);
        $this->assertContains('brand_title', $keys);
        $this->assertContains('password', $keys);

        $capture->refresh();
        $this->assertSame('done', $capture->status);
        $this->assertSame("lote1/{$capture->id}-desktop-1.jpg", $capture->shot('desktop'));
        $this->assertSame("lote1/{$capture->id}-iphone-1.jpg", $capture->shot('iphone'));
        $this->assertNull($capture->shot('desktop', 'second'));
        $this->assertSame(['desktop', 'android', 'iphone'], $capture->devicesWithShots());
        $this->assertTrue($capture->keep, 'a primeira visita fica como prova');

        // Aviso de domínio novo, com a foto do navegador; sem canal, não sai (e sem IA nos testes).
        $alert = LookalikeAlert::query()->sole();
        $this->assertSame(['new', 'critical', 'desktop'], [$alert->kind, $alert->risk, $alert->photo['device']]);
        $this->assertSame(['skipped', 'no_channel'], [$alert->status, $alert->note]);
        $this->assertFileDoesNotExist($this->browserDir.'/outbox/lote1.json');
    }

    public function test_screen_that_changes_after_ten_seconds_keeps_both_prints_and_unchanged_keeps_one(): void
    {
        $lookalike = $this->lookalike($this->watch());
        $capture = $this->browserAnswer($lookalike, $this->answer(devices: [
            'android' => ['first' => 'lote1/x-android-1.jpg', 'second' => 'lote1/x-android-2.jpg', 'changed' => true],
        ]));

        app(CaptureIngestor::class)->ingest();
        $capture->refresh();

        $this->assertTrue($capture->changed('android'));
        $this->assertSame('second', $capture->latestMoment('android'));
        $this->assertFalse($capture->changed('desktop'));
        $this->assertSame('first', $capture->latestMoment('desktop'));
    }

    public function test_scam_that_shows_only_on_the_phone_sends_the_phone_photo(): void
    {
        $lookalike = $this->lookalike($this->watch(), 'acmepay-pix.com', ['registered_at' => now()->subDays(5)]);
        $phone = ['title' => 'Acme Pay · Pague com Pix', 'text' => 'Acme Pay: confirme seu CPF e pague a taxa com Pix copia e cola.', 'forms' => ['inputs' => 2, 'cpf' => 1, 'pix' => 1]];
        $this->browserAnswer($lookalike, $this->answer(
            ['title' => 'Acme Pay · Pague com Pix', 'forms' => ['inputs' => 2, 'cpf' => 1, 'pix' => 1], 'final_url' => 'https://acmepay-pix.com/', 'desktop' => ['title' => '', 'text' => 'Em breve', 'text_length' => 8]],
            ['android' => $phone, 'iphone' => $phone],
        ));

        app(CaptureIngestor::class)->ingest();

        $lookalike->refresh();
        $this->assertContains('mobile_only', array_column($lookalike->signals, 'key'));
        $this->assertSame('android', LookalikeAlert::query()->sole()->photo['device'], 'o golpe só aparece no celular: vai a foto dele');
    }

    public function test_page_that_looks_like_the_client_site_counts_as_imitation(): void
    {
        $watch = $this->watch(attributes: ['reference' => ['pages' => [['url' => 'https://acmepay.com.br/', 'title' => 'Acme Pay - Início', 'shots' => ['desktop' => 'ref/1/a/p1-desktop.jpg']]]]]);
        $lookalike = $this->lookalike($watch, 'acmepey.com', ['techniques' => ['vogal']]);
        $this->browserAnswer($lookalike, $this->answer(['final_url' => 'https://acmepey.com/', 'desktop' => ['similar' => ['page' => 0, 'score' => 93]]]));

        app(CaptureIngestor::class)->ingest();

        $signal = collect($lookalike->refresh()->signals)->firstWhere('key', 'looks_like_you');
        $this->assertNotNull($signal);
        $this->assertStringContainsString('Acme Pay - Início', $signal['label']);
        $this->assertStringContainsString('93%', $signal['label']);
    }

    public function test_parked_blank_and_unrelated_sites_stay_quiet_and_do_not_alert(): void
    {
        $watch = $this->watch();

        $parked = $this->lookalike($watch, 'acmepay.net');
        $this->browserAnswer($parked, $this->answer(['title' => 'acmepay.net is for sale!', 'text' => 'This domain may be for sale. Make an offer.', 'final_url' => 'https://acmepay.net/']));
        app(CaptureIngestor::class)->ingest();
        $this->assertSame(['parked', 'watch'], [$parked->refresh()->state, $parked->risk]);

        $blank = $this->lookalike($watch, 'acmepay.online');
        $this->browserAnswer($blank, $this->answer(['title' => '', 'text' => '', 'text_length' => 0, 'images' => 0, 'final_url' => 'https://acmepay.online/']));
        app(CaptureIngestor::class)->ingest();
        $this->assertSame(['blank', 'watch'], [$blank->refresh()->state, $blank->risk]);

        // Outro negócio, registrado há anos, sem citar a marca: só parecido.
        $other = $this->lookalike($watch, 'acmepey.com', ['registered_at' => now()->subYears(6), 'techniques' => ['vogal']]);
        $this->browserAnswer($other, $this->answer(['final_url' => 'https://acmepey.com/']));
        app(CaptureIngestor::class)->ingest();
        $this->assertSame(['content', 'low'], [$other->refresh()->state, $other->risk]);

        $this->assertSame(0, LookalikeAlert::query()->count(), '"Vigiar" e "Baixo" vão só no resumo');
    }

    public function test_password_field_of_another_site_after_a_redirect_is_not_a_clone(): void
    {
        $lookalike = $this->lookalike($this->watch(), 'acmepey.com', ['registered_at' => now()->subDays(40), 'techniques' => ['vogal']]);
        $this->browserAnswer($lookalike, $this->answer([
            'final_url' => 'https://afiliado-qualquer.example/cadastro?aff=18',
            'title' => 'Crie sua conta',
            'forms' => ['inputs' => 2, 'password' => 1, 'email' => 1],
        ]));

        app(CaptureIngestor::class)->ingest();

        $lookalike->refresh();
        $keys = array_column($lookalike->signals, 'key');
        $this->assertContains('redirects_elsewhere', $keys);
        $this->assertNotContains('password', $keys);
        $this->assertSame('watch', $lookalike->risk, 'desvia o tráfego de quem erra o nome, mas não imita a marca');
    }

    public function test_page_opened_despite_an_invalid_certificate_is_content_and_keeps_the_reason(): void
    {
        $lookalike = $this->lookalike($this->watch(), 'acmepay-login.com');
        $capture = $this->browserAnswer($lookalike, $this->answer([
            'certificate_error' => 'net::ERR_CERT_DATE_INVALID',
            'desktop' => ['certificate_error' => 'net::ERR_CERT_DATE_INVALID'],
        ]));

        app(CaptureIngestor::class)->ingest();

        $capture->refresh();
        $this->assertSame('content', $lookalike->refresh()->state, 'a página abriu: não é "fora do ar"');
        $this->assertSame('net::ERR_CERT_DATE_INVALID', $capture->data['certificate_error']);
        $this->assertSame('net::ERR_CERT_DATE_INVALID', $capture->device('desktop')['certificate_error']);
        $this->assertSame('vencido ou ainda não válido', $capture->certificateProblem());
    }

    public function test_valid_certificate_leaves_no_certificate_problem(): void
    {
        $lookalike = $this->lookalike($this->watch(), 'acmepay-login.com');
        $capture = $this->browserAnswer($lookalike, $this->answer());

        app(CaptureIngestor::class)->ingest();

        $this->assertNull($capture->refresh()->certificateProblem());
    }

    public function test_redirect_to_the_client_site_is_low_and_never_followed(): void
    {
        $lookalike = $this->lookalike($this->watch(), 'acmepay.com');
        $this->browserAnswer($lookalike, [
            'ok' => false,
            'error' => 'net::ERR_BLOCKED_BY_CLIENT',
            'own_navigation' => 'https://www.acmepay.com.br/',
            'own_requests' => [['url' => 'https://www.acmepay.com.br/', 'type' => 'document', 'navigation' => true]],
            'devices' => ['desktop' => ['ok' => false, 'own_requests' => 1]],
        ]);

        app(CaptureIngestor::class)->ingest();

        $lookalike->refresh();
        $this->assertSame('redirects_to_you', $lookalike->state);
        $this->assertSame('low', $lookalike->risk);
        $this->assertNotContains('hotlink', array_column($lookalike->signals, 'key'), 'a navegação não conta como arquivo puxado');
    }

    public function test_parked_domain_that_wakes_up_as_a_clone_alerts_as_raised_and_keeps_the_evidence(): void
    {
        $watch = $this->watch();
        $lookalike = $this->lookalike($watch, 'acmepay-pix.com', ['registered_at' => now()->subDays(10)]);
        $this->browserAnswer($lookalike, $this->answer(['title' => 'Domínio à venda', 'text' => 'Este domínio está à venda', 'final_url' => 'https://acmepay-pix.com/']));
        app(CaptureIngestor::class)->ingest();
        $this->assertSame('watch', $lookalike->refresh()->risk);

        $this->browserAnswer($lookalike, $this->answer([
            'title' => 'Acme Pay - Pague com Pix',
            'final_url' => 'https://acmepay-pix.com/',
            'forms' => ['inputs' => 3, 'cpf' => 1, 'pix' => 1],
            'desktop' => ['title' => 'Acme Pay - Pague com Pix', 'forms' => ['inputs' => 3, 'cpf' => 1, 'pix' => 1]],
        ]));
        app(CaptureIngestor::class)->ingest();

        $lookalike->refresh();
        $this->assertSame(['critical', 'watch'], [$lookalike->risk, $lookalike->previous_risk]);
        $this->assertSame(2, LookalikeCapture::query()->where('keep', true)->count());
        $alert = LookalikeAlert::query()->sole();
        $this->assertSame(['raised', 'watch'], [$alert->kind, $alert->previous_risk]);
    }

    public function test_domain_the_client_marked_as_theirs_never_alerts(): void
    {
        $lookalike = $this->lookalike($this->watch(), 'acmepay.com', ['verdict' => 'ours']);
        $this->browserAnswer($lookalike, $this->answer(['title' => 'Acme Pay - Entrar', 'forms' => ['password' => 1, 'inputs' => 1], 'final_url' => 'https://acmepay.com/']));

        app(CaptureIngestor::class)->ingest();

        $this->assertSame(0, LookalikeAlert::query()->count());
    }

    public function test_reference_answer_replaces_the_client_prints(): void
    {
        $watch = $this->watch();
        $old = $this->fakeShot("ref/{$watch->id}/velho/p1-desktop.jpg");
        $watch->update(['reference' => ['pages' => [['url' => 'https://acmepay.com.br/', 'title' => 'Velho', 'shots' => ['desktop' => "ref/{$watch->id}/velho/p1-desktop.jpg"]]]], 'reference_status' => 'pending']);

        File::ensureDirectoryExists($this->browserDir.'/outbox');
        file_put_contents($this->browserDir.'/outbox/ref1.json', json_encode([
            'batch' => 'ref1', 'mode' => 'reference', 'watch' => $watch->id,
            'pages' => [
                ['url' => 'https://acmepay.com.br/', 'title' => 'Acme Pay', 'shots' => ['desktop' => "ref/{$watch->id}/novo/p1-desktop.jpg", 'android' => "ref/{$watch->id}/novo/p1-android.jpg"]],
                ['url' => 'https://acmepay.com.br/entrar', 'title' => 'Entrar', 'shots' => ['desktop' => "ref/{$watch->id}/novo/p2-desktop.jpg", 'iphone' => '../../.env']],
                ['url' => 'https://acmepay.com.br/x', 'title' => 'De outro Radar', 'shots' => ['desktop' => 'ref/999/x/p3-desktop.jpg']],
            ],
        ]));

        $this->assertSame(1, app(CaptureIngestor::class)->ingest()['references']);

        $watch->refresh();
        $this->assertSame('done', $watch->reference_status);
        $this->assertCount(2, $watch->referencePages(), 'página com print de fora da pasta deste Radar não entra');
        $this->assertSame(["ref/{$watch->id}/novo/p1-desktop.jpg", "ref/{$watch->id}/novo/p2-desktop.jpg"], $watch->referenceShots('desktop'));
        $this->assertSame([], $watch->referenceShots('iphone'));
        $this->assertFileDoesNotExist($old);
    }

    public function test_foreign_or_stale_answers_are_ignored_and_bad_paths_are_dropped(): void
    {
        $lookalike = $this->lookalike($this->watch());
        $capture = $this->browserAnswer($lookalike, $this->answer());
        // Outro lote com o mesmo id não vale.
        file_put_contents($this->browserDir.'/outbox/lote1.json', json_encode(['batch' => 'outro', 'results' => [$this->answer() + ['id' => $capture->id]]]));
        app(CaptureIngestor::class)->ingest();
        $this->assertSame('queued', $capture->refresh()->status);

        $this->browserAnswer($lookalike, $this->answer(devices: ['android' => ['first' => '../../.env'], 'iphone' => ['first' => 'lote1/../../x.jpg']]));
        app(CaptureIngestor::class)->ingest();
        $latest = LookalikeCapture::query()->latest('id')->first();
        $this->assertNull($latest->shot('android'));
        $this->assertNull($latest->shot('iphone'));

        // O pedido antigo que o navegador nunca respondeu vira falha.
        $capture->update(['requested_at' => now()->subHours(7)]);
        $this->assertSame(1, app(CaptureIngestor::class)->expireStale());
        $this->assertSame('failed', $capture->refresh()->status);
    }
}
