<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\User;
use App\Services\Auth\RejectedAttempts;
use App\Services\Monitoring\PageViewRecorder;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Login e cadastro: cada tentativa recusada vira um evento com o motivo, o IP
 * e o código do e-mail, nunca a senha nem o e-mail (a /privacidade promete).
 */
class RejectedAttemptsTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const PASSWORD = 'senha-secreta-da-ana';

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        AccessEvent::query()->delete();
    }

    private function login(string $route, string $email, string $password = self::PASSWORD, array $extra = []): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->post(route($route), ['email' => $email, 'password' => $password] + $extra);
    }

    /** @return list<array{?string, ?string}> */
    private function attempts(): array
    {
        return AccessEvent::query()->orderBy('id')->get()->map(fn (AccessEvent $e) => [$e->auth, $e->account_hmac])->all();
    }

    private function withCaptcha(?bool $success): void
    {
        config(['services.turnstile.site_key' => '1x00000000000000000000AA', 'services.turnstile.secret_key' => '1x0000000000000000000000000000000AA']);
        Http::fake([self::VERIFY => $success === null ? Http::failedConnection() : Http::response(['success' => $success, 'action' => 'login'])]);
    }

    public function test_a_wrong_password_is_recorded_without_the_password_or_the_email(): void
    {
        User::factory()->create(['email' => 'ana@exemplo.com']);
        User::factory()->create(['email' => 'adm@exemplo.com', 'is_admin' => true]);

        $this->login('login', 'Ana@Exemplo.com')->assertSessionHasErrors('email');
        $this->login('admin.login', 'adm@exemplo.com')->assertSessionHasErrors('email');

        $this->assertSame([
            ['login_senha', RejectedAttempts::hmac('ana@exemplo.com')],
            ['admin_senha', RejectedAttempts::hmac('adm@exemplo.com')],
        ], $this->attempts());

        $event = AccessEvent::query()->first();
        $this->assertSame(['autenticacao', 'POST', '203.0.113.7', 302], [$event->category, $event->method, $event->ip, $event->status]);

        $row = strtolower((string) json_encode(AccessEvent::query()->get()->toArray()));
        $this->assertStringNotContainsString(self::PASSWORD, $row);
        $this->assertStringNotContainsString('ana@exemplo.com', $row);
        $this->assertStringNotContainsString('adm@exemplo.com', $row);
    }

    public function test_the_sixth_attempt_is_the_lock(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->login('login', 'ana@exemplo.com');
        }

        $this->assertSame([...array_fill(0, 5, 'login_senha'), 'login_trava'], array_column($this->attempts(), 0));
    }

    public function test_a_refused_captcha_is_recorded_and_a_cloudflare_failure_is_not(): void
    {
        $this->withCaptcha(false);
        $this->login('login', 'ana@exemplo.com', extra: ['cf-turnstile-response' => 'token-ruim'])->assertSessionHasErrors('cf-turnstile-response');
        $this->login('login', 'ana@exemplo.com')->assertSessionHasErrors('cf-turnstile-response');

        // Sem e-mail na tela: o cadastro com o GitHub grava o captcha sem código.
        config(['services.github.client_id' => 'Iv1.abc', 'services.github.client_secret' => 'segredo']);
        $this->post(route('register.github'))->assertSessionHasErrors('cf-turnstile-response');

        $this->withCaptcha(null);
        $this->login('login', 'ana@exemplo.com', extra: ['cf-turnstile-response' => 'token'])->assertSessionHasErrors('cf-turnstile-response');

        $hmac = RejectedAttempts::hmac('ana@exemplo.com');
        $this->assertSame([['captcha', $hmac], ['captcha', $hmac], ['captcha', null]], $this->attempts());
    }

    public function test_an_email_that_already_has_an_account_is_recorded_at_the_sign_up(): void
    {
        User::factory()->create(['email' => 'ana@exemplo.com']);

        $this->post(route('register'), ['name' => 'Ana', 'email' => 'ana@exemplo.com', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => 'Já existe uma conta com este e-mail.']);
        // Outro erro de validação não é tentativa contra uma conta.
        $this->post(route('register'), ['name' => 'Bia', 'email' => 'bia@exemplo.com', 'password' => 'curta', 'password_confirmation' => 'curta'])
            ->assertSessionHasErrors('password');

        $this->assertSame([['cadastro_email_existente', RejectedAttempts::hmac('ana@exemplo.com')]], $this->attempts());
    }

    public function test_recorded_even_with_the_monitor_off_and_the_right_login_is_not(): void
    {
        Settings::set(PageViewRecorder::SETTING, false);
        User::factory()->create(['email' => 'ana@exemplo.com']);

        $this->login('login', 'ana@exemplo.com', 'password')->assertRedirect();
        $this->assertAuthenticated();
        auth()->logout();
        $this->login('login', 'ana@exemplo.com');

        $this->assertSame(['login_senha'], array_column($this->attempts(), 0));
    }

    public function test_the_code_of_the_email_ignores_case_and_spaces(): void
    {
        $this->assertSame(RejectedAttempts::hmac('A@x.com'), RejectedAttempts::hmac(' a@x.com '));
        $this->assertSame(32, strlen((string) RejectedAttempts::hmac('a@x.com')));
        $this->assertNotSame(RejectedAttempts::hmac('a@x.com'), RejectedAttempts::hmac('b@x.com'));
        $this->assertNull(RejectedAttempts::hmac('  '));
    }
}
