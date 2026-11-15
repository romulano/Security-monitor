<?php

namespace App\Console\Commands;

use App\Jobs\ScanGithub;
use App\Models\GithubMonitor;
use App\Models\User;
use App\Services\Github\GithubScanner;
use Illuminate\Console\Command;

class GithubScanCommand extends Command
{
    protected $signature = 'secmon:github-scan
        {email? : varre agora o monitoramento do GitHub deste cliente}
        {--due : agendado: varre quem venceu a varredura diária}
        {--sync : roda aqui, sem fila (mostra o registro da varredura)}';

    protected $description = 'Monitoramento do GitHub: busca em código e repositórios públicos, e os achados novos vão para as duas etapas da IA';

    public function handle(GithubScanner $scanner): int
    {
        if (! config('secmon.github.enabled')) {
            $this->warn('Monitoramento do GitHub desligado (GITHUB_MONITOR_ENABLED).');

            return self::SUCCESS;
        }

        if ($this->option('due')) {
            // Roda a cada 15 minutos; cada monitoramento vence 24 h depois da última varredura.
            $due = GithubMonitor::query()
                ->where('status', 'active')
                // "Rodando" há mais de 1 h é varredura que morreu com o worker: vale de novo.
                ->where(fn ($q) => $q->where('scan_status', '!=', 'running')->orWhere('updated_at', '<', now()->subHour()))
                ->where(fn ($q) => $q->whereNull('next_scan_at')->orWhere('next_scan_at', '<=', now()))
                ->pluck('id');

            foreach ($due as $id) {
                ScanGithub::dispatch($id);
            }

            $this->info(count($due).' varredura(s) do GitHub na fila.');

            return self::SUCCESS;
        }

        $email = $this->argument('email');
        $monitor = $email ? User::query()->where('email', $email)->first()?->githubMonitor : null;

        if (! $monitor || ! $monitor->isActive()) {
            $this->error($email ? 'Esse cliente não tem monitoramento do GitHub ativo.' : 'Informe o e-mail do cliente ou use --due.');

            return self::FAILURE;
        }

        if (! $this->option('sync')) {
            ScanGithub::dispatch($monitor->id);
            $this->info('Varredura na fila github.');

            return self::SUCCESS;
        }

        $stats = $scanner->scan($monitor);

        foreach ($monitor->refresh()->last_log ?? [] as $line) {
            $this->line('· '.$line);
        }

        $this->info("{$stats['queries']} busca(s), {$stats['results']} resultado(s), {$stats['new']} achado(s) novo(s), {$stats['changed']} mudado(s), {$stats['gone']} removido(s).");

        foreach ($stats['skipped'] as $skipped) {
            $this->warn('pulado: '.$skipped);
        }

        return self::SUCCESS;
    }
}
