<?php

namespace App\Console\Commands;

use App\Jobs\ScanSecuritySite;
use App\Models\SecuritySite;
use App\Services\Security\CheckCatalog;
use App\Services\Security\ScanRecorder;
use App\Services\Security\SiteHost;
use App\Services\Security\SiteScanner;
use Illuminate\Console\Command;

class SecurityScanCommand extends Command
{
    protected $signature = 'secmon:security-scan
        {host? : varre agora este site cadastrado em Admin → Segurança}
        {--due : agendado: enfileira os sites cuja hora de verificar venceu}
        {--sync : roda aqui, sem fila, e mostra o resultado}';

    protected $description = 'Monitoria de segurança dos sites do admin: certificado, cabeçalhos, DNS e arquivos expostos (só leitura)';

    public function handle(SiteScanner $scanner, ScanRecorder $recorder): int
    {
        if ($this->option('due')) {
            $ids = SecuritySite::query()->due()->pluck('id');

            foreach ($ids as $id) {
                ScanSecuritySite::dispatch($id);
            }

            $this->info($ids->count().' site(s) na fila de segurança.');

            return self::SUCCESS;
        }

        $host = SiteHost::normalize($this->argument('host'));
        $site = $host ? SecuritySite::query()->where('host', $host)->first() : null;

        if (! $site) {
            $this->error($this->argument('host') ? 'Site não cadastrado em Admin → Segurança.' : 'Informe o site ou use --due.');

            return self::FAILURE;
        }

        if (! $this->option('sync')) {
            ScanSecuritySite::dispatch($site->id);
            $this->info("{$site->host} na fila de segurança.");

            return self::SUCCESS;
        }

        $report = $scanner->scan($site);
        $recorder->record($site, $report);

        $this->table(['Verificação', 'Estado', 'Valor'], array_map(
            fn ($r) => [CheckCatalog::label($r->id).($r->subject !== '' ? " ({$r->subject})" : ''), $r->status, $r->value],
            $report->results,
        ));
        $this->info("{$site->host}: {$report->status}, nota {$report->score}/100.");

        return self::SUCCESS;
    }
}
