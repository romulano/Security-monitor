<?php

namespace App\Jobs;

use App\Models\KeySite;
use App\Services\Keys\KeyCrawler;
use App\Services\Keys\KeyLedger;
use App\Services\Keys\KeySites;
use App\Support\DomainActivity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uma etapa da varredura de chaves de um domínio comprovado (a 1ª, ou a continuação: a varredura de
 * 1.000 páginas vai em etapas de 7 minutos, o estado guardado em key_sites.scan_state entre elas). Fila própria (`keys`, no worker do Radar, que
 * aceita jobs longos): a navegação devagar leva alguns minutos e não atrasa alerta nem análise.
 */
class ScanSiteKeys implements ShouldQueue
{
    use Queueable;

    public int $timeout = 570;

    public int $tries = 1;

    public function __construct(public int $siteId, public bool $continues = false)
    {
        $this->onQueue('keys');
    }

    public function handle(KeyCrawler $crawler, KeyLedger $ledger): void
    {
        $site = KeySite::query()->with('ownedDomain')->find($this->siteId);

        // A 1ª etapa sai da fila; a continuação, da varredura que está rodando com o estado guardado.
        if (! $site || ($this->continues ? $site->scan_status !== 'running' || $site->scan_state === null : $site->scan_status !== 'queued')) {
            return;
        }

        $owned = $site->ownedDomain;
        $finish = fn (array $values) => $site->forceFill($values + ['scan_status' => 'idle', 'scan_state' => null, 'next_scan_at' => now()->addDays(KeySites::EVERY_DAYS)])->save();

        // A posse é a autorização: conferida de novo agora.
        if (! $site->isActive() || ! KeySites::provable($owned)) {
            $finish(['last_result' => 'failed', 'last_error' => 'A posse do domínio não está mais comprovada.']);

            return;
        }

        // Com um teste de segurança no domínio, a varredura fica para daqui a 1 hora (o tráfego dos dois se somaria).
        if (! $this->continues && DomainActivity::pentestActive($owned->domain)) {
            $site->forceFill(['scan_status' => 'idle', 'next_scan_at' => now()->addHour()])->save();

            return;
        }

        $site->forceFill(['scan_status' => 'running', 'started_at' => now()])->save();

        // De minuto em minuto: as chaves achadas até ali vão para o registro, e o estado fica guardado (o sinal de vida da
        // varredura). O job que o deploy matar não leva nada junto: a semanal retoma dali (KeySites::due).
        $checkpoint = function (array $partial) use ($ledger, $owned, $site) {
            DB::transaction(fn () => $ledger->record($owned, $partial['keys'], 'scan', now()));

            if ($partial['state'] !== null) {
                $site->forceFill(['scan_state' => $partial['state'], 'started_at' => now()])->save();
            }
        };

        try {
            $result = $crawler->crawl($owned->domain, (array) ($site->extra_paths ?? []), $this->continues ? $site->scan_state : null, $checkpoint);
            $now = now();
            // As chaves da varredura até aqui (as contagens são da varredura inteira): a etapa que cair não perde as anteriores.
            $found = DB::transaction(fn () => $ledger->record($owned, $result['keys'], 'scan', $now));

            if ($result['state'] !== null) {
                $site->forceFill(['scan_state' => $result['state'], 'started_at' => $now])->save();
                self::dispatch($site->id, true);

                return;
            }

            $finish([
                'last_scan_at' => $now,
                'last_result' => match (true) {
                    ! $result['reached'] => 'failed',
                    $result['truncated'] => 'partial',
                    default => 'ok',
                },
                'last_error' => match (true) {
                    ! $result['reached'] => "O site de {$owned->domain} não respondeu.",
                    $result['blocked'] => 'O site começou a recusar a navegação (403 ou 429 seguidos) e a varredura parou ali.',
                    default => null,
                },
                'last_summary' => ['pages' => $result['pages'], 'scripts' => $result['scripts'], 'sitemap_urls' => $result['sitemap_urls'], 'archive_urls' => $result['archive_urls'],
                    'requests' => $result['requests'], 'hosts' => count($result['hosts']), 'keys' => $found['keys'], 'new' => $found['new'],
                    'truncated' => $result['truncated'], 'seconds' => $result['seconds'], 'net_seconds' => $result['net_seconds'], 'unanswered' => $result['unanswered']],
            ]);
        } catch (Throwable $e) {
            Log::warning('Varredura de chaves falhou', ['dominio' => $owned->domain, 'erro' => $e->getMessage()]);
            // As chaves achadas até o último minuto já estão no registro: ficam.
            $finish(['last_scan_at' => now(), 'last_result' => 'failed', 'last_error' => 'A varredura falhou no meio; as chaves achadas até ali ficaram, e ela vai de novo na próxima vez.']);
        }
    }
}
