<?php

namespace App\Jobs;

use App\Models\SecuritySite;
use App\Services\Security\ScanRecorder;
use App\Services\Security\SiteScanner;
use App\Support\DomainActivity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Uma varredura de segurança de um site do admin. Fila própria (`security`): rede lenta não atrasa alerta nem análise. */
class ScanSecuritySite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public int $siteId)
    {
        $this->onQueue('security');
    }

    public function handle(SiteScanner $scanner, ScanRecorder $recorder): void
    {
        $site = SecuritySite::query()->find($this->siteId);

        if (! $site || ! $site->enabled) {
            return;
        }

        // Com um teste de segurança no site, a varredura fica para daqui a 1 hora, sem contar como falha (T10).
        if (DomainActivity::pentestActive($site->host)) {
            $site->forceFill(['next_check_at' => now()->addHour()])->save();
            Log::info('Varredura de segurança adiada: teste de segurança em andamento', ['site' => $site->host]);

            return;
        }

        try {
            $recorder->record($site, $scanner->scan($site));
        } catch (Throwable $e) {
            Log::warning('Varredura de segurança falhou', ['site' => $site->host, 'erro' => $e->getMessage()]);
            // Registra o erro e agenda a próxima: o painel precisa saber que a varredura falhou.
            $recorder->recordFailure($site->refresh(), $e->getMessage());
        }
    }
}
