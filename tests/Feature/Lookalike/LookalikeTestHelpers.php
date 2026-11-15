<?php

namespace Tests\Feature\Lookalike;

use App\Models\LookalikeCapture;
use App\Models\LookalikeDomain;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Tests\Feature\Alerts\AlertsTestHelpers;

/** Domínio comprovado, Radar, respostas do navegador e pasta do navegador temporária. */
trait LookalikeTestHelpers
{
    use AlertsTestHelpers;

    protected string $browserDir;

    protected function useTempBrowserDir(): void
    {
        // O processo e um sorteio, não o uniqid(): os processos paralelos do PHPUnit no mesmo microssegundo davam o mesmo nome,
        // e o 2º mkdir caía com "File exists" (CI da main, 11/11).
        $this->browserDir = sys_get_temp_dir().'/secmon-lookalike-'.getmypid().'-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->browserDir.'/shots');
        config(['secmon.lookalikes.browser_dir' => $this->browserDir]);
    }

    protected function tearDownBrowserDir(): void
    {
        if (isset($this->browserDir)) {
            File::deleteDirectory($this->browserDir);
        }
    }

    /** Marcas de cada domínio criado por verifiedDomain(), para o Radar dele. */
    protected array $radarBrands = [];

    /** Domínio de um Radar (sem alerta de notícias), já com a posse comprovada. */
    protected function verifiedDomain(?User $user = null, string $name = 'acmepay.com.br', array $brands = ['Acme Pay'], string $consent = 'v2'): MonitoredDomain
    {
        $domain = MonitoredDomain::query()->create([
            'user_id' => ($user ?? $this->client())->id,
            'domain' => $name,
            'verify_token' => 'abc123',
            'consent_at' => now(),
            'consent_version' => $consent,
            'verified_at' => now(),
        ]);
        $this->radarBrands[$domain->id] = $brands;

        return $this->proveRow($domain);
    }

    /** O Radar do domínio, com a entrevista concluída e a varredura feita. */
    protected function watch(?MonitoredDomain $domain = null, array $attributes = []): LookalikeWatch
    {
        $domain ??= $this->verifiedDomain();

        return LookalikeWatch::query()->updateOrCreate(['domain_id' => $domain->id], $attributes + [
            'user_id' => $domain->user_id,
            'company_name' => 'Acme Pagamentos',
            'brands' => $this->radarBrands[$domain->id] ?? ['Acme Pay'],
            'step_reached' => 3,
            'completed_at' => now(),
            'status' => 'active',
            'scan_status' => 'done',
            'last_scan_at' => now(),
            'next_scan_at' => now()->addDays(7),
        ]);
    }

    protected function lookalike(LookalikeWatch $watch, string $host = 'acmepay-login.com', array $attributes = []): LookalikeDomain
    {
        return $watch->lookalikes()->create($attributes + [
            'host' => $host,
            'techniques' => ['palavra'],
            'ips' => ['45.90.20.10'],
            'state' => 'pending',
            'risk' => 'watch',
            'first_seen_at' => now(),
        ]);
    }

    /** O que um aparelho viu, no formato do capture.mjs. */
    protected function seen(array $overrides = []): array
    {
        return array_replace_recursive([
            'ok' => true,
            'status' => 200,
            'title' => 'Página qualquer',
            'text' => 'Bem-vindo ao nosso blog de receitas, com dicas de cozinha toda semana e muito mais conteúdo.',
            'text_length' => 95,
            'images' => 4,
            'forms' => ['inputs' => 0, 'password' => 0, 'email' => 0, 'card' => 0, 'cpf' => 0, 'pix' => 0],
            'own_requests' => 0,
            'changed' => false,
            'similar' => null,
        ], $overrides);
    }

    /**
     * Visita pedida e a resposta do navegador já em outbox/, como o capture.mjs deixa.
     * `devices` sem prints recebe o caminho padrão lote1/<id>-<aparelho>-1.jpg.
     */
    protected function browserAnswer(LookalikeDomain $lookalike, array $result): LookalikeCapture
    {
        $capture = $lookalike->captures()->create(['status' => 'queued', 'batch' => 'lote1', 'requested_at' => now()]);

        foreach ($result['devices'] ?? [] as $device => $seen) {
            if (($seen['ok'] ?? false) && ! array_key_exists('first', $seen)) {
                $result['devices'][$device]['first'] = "lote1/{$capture->id}-{$device}-1.jpg";
            }
        }

        File::ensureDirectoryExists($this->browserDir.'/outbox');
        file_put_contents($this->browserDir.'/outbox/lote1.json', json_encode([
            'batch' => 'lote1',
            'mode' => 'lookalikes',
            'results' => [$result + ['id' => $capture->id]],
        ]));

        return $capture;
    }

    /** Arquivo de print de mentira dentro de shots/. */
    protected function fakeShot(string $relative): string
    {
        $file = $this->browserDir.'/shots/'.$relative;
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, 'jpeg');

        return $file;
    }
}
