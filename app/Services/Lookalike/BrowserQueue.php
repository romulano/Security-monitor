<?php

namespace App\Services\Lookalike;

use App\Models\LookalikeCapture;
use App\Models\LookalikeDomain;
use App\Models\LookalikeWatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A conversa com o navegador (docker/browser) é por arquivo, sem rede entre os
 * dois: o Security Monitor escreve o pedido em inbox/, o container sobe (systemd vê o
 * arquivo), abre as páginas, grava prints em shots/ e a resposta em outbox/,
 * e morre. O container não enxerga o banco, o Redis nem o app.
 *
 * Todo pedido leva os endereços do próprio Security Monitor (`block_ips`), que o proxy do
 * navegador recusa como recusa IP interno: o container não tem .env.
 */
class BrowserQueue
{
    /** Pedido sem resposta depois disso: o navegador não subiu ou morreu no meio. */
    public const STALE_HOURS = 6;

    public function dir(string $sub = ''): string
    {
        $base = rtrim((string) config('secmon.lookalikes.browser_dir'), '/');

        return $sub === '' ? $base : $base.'/'.$sub;
    }

    /**
     * Pede a visita do navegador a estes domínios (um lote por arquivo).
     *
     * @param  Collection<int, LookalikeDomain>  $lookalikes
     * @param  list<string>  $blockHosts  domínios do cliente: o navegador não pede nada a eles
     * @return int visitas pedidas
     */
    public function enqueue(LookalikeWatch $watch, Collection $lookalikes, array $blockHosts): int
    {
        $waiting = LookalikeCapture::query()
            ->whereIn('lookalike_id', $lookalikes->pluck('id'))
            ->where('status', 'queued')
            ->pluck('lookalike_id')
            ->flip();

        $todo = $lookalikes->reject(fn (LookalikeDomain $d) => $waiting->has($d->id))->values();

        foreach ($todo->chunk(max(1, (int) config('secmon.lookalikes.capture_batch', 25))) as $chunk) {
            // Pedido sem arquivo nunca seria atendido: as linhas só ficam se o arquivo for gravado.
            DB::transaction(function () use ($chunk, $watch, $blockHosts) {
                $batch = strtolower((string) Str::ulid());
                $items = [];

                foreach ($chunk as $lookalike) {
                    $capture = $lookalike->captures()->create([
                        'status' => 'queued',
                        'batch' => $batch,
                        'requested_at' => now(),
                    ]);
                    $items[] = ['id' => $capture->id, 'host' => $lookalike->host];
                }

                $this->write('inbox', $batch, [
                    'batch' => $batch,
                    'mode' => 'lookalikes',
                    'watch' => $watch->id,
                    'created_at' => now()->toIso8601String(),
                    'block_hosts' => array_values(array_unique($blockHosts)),
                    'block_ips' => $this->ownAddresses(),
                    // Prints do site do cliente, para o navegador medir a semelhança visual.
                    'reference' => collect(array_keys(LookalikeCapture::DEVICES))->mapWithKeys(fn ($device) => [$device => $watch->referenceShots($device)])->filter()->all(),
                    'items' => $items,
                ]);
            });
        }

        return $todo->count();
    }

    /**
     * Pede os prints de referência do site do cliente: a página inicial e duas
     * linkadas nela, nos 3 aparelhos, com "SecurityMonitorBot" no User-Agent (o termo v3
     * do passo 5 autoriza; o cliente pode liberar o robô no firewall por ele).
     */
    public function enqueueReference(LookalikeWatch $watch, string $url, array $ownDomains): string
    {
        // "00ref…" vem antes de qualquer ULID ("01…") na ordem dos arquivos: o navegador tira a
        // referência primeiro e já compara os domínios com ela na mesma subida.
        $batch = '00ref'.strtolower((string) Str::ulid());

        $this->write('inbox', $batch, [
            'batch' => $batch,
            'mode' => 'reference',
            'watch' => $watch->id,
            'created_at' => now()->toIso8601String(),
            'block_ips' => $this->ownAddresses(),
            'site' => [
                'url' => $url,
                'own' => array_values(array_unique($ownDomains)),
                'pages' => (int) config('secmon.lookalikes.reference_pages', 3),
                'ua_suffix' => (string) config('secmon.lookalikes.reference_ua_suffix'),
                'dir' => "ref/{$watch->id}/{$batch}",
            ],
        ]);

        $watch->forceFill(['reference_status' => 'pending'])->save();

        return $batch;
    }

    /** Respostas que o navegador deixou em outbox/ (o arquivo sai depois de lido). @return list<array> */
    public function results(): array
    {
        $out = [];

        foreach (glob($this->dir('outbox').'/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            @unlink($file);

            if (is_array($data) && isset($data['batch'])) {
                $out[] = $data;
            }
        }

        return $out;
    }

    /** Lotes ainda esperando o navegador. */
    public function waiting(): int
    {
        return count(glob($this->dir('inbox').'/*.json') ?: []) + count(glob($this->dir('work').'/*.json') ?: []);
    }

    /**
     * Os endereços do próprio Security Monitor (`secmon.own_addresses`), só os que são IP ou faixa válidos:
     * um valor torto no .env não chega ao navegador.
     *
     * @return list<string>
     */
    private function ownAddresses(): array
    {
        return collect((array) config('secmon.own_addresses', []))
            ->map(fn ($entry) => trim((string) $entry))
            ->filter(function (string $entry) {
                [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
                $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;

                return filter_var($ip, FILTER_VALIDATE_IP) && ($bits === null || (ctype_digit($bits) && (int) $bits <= $max));
            })
            ->unique()
            ->values()
            ->all();
    }

    private function write(string $sub, string $name, array $payload): void
    {
        $dir = $this->dir($sub);
        File::ensureDirectoryExists($dir, 0775);
        $tmp = $dir.'/.'.$name.'.tmp';

        if (file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false || ! rename($tmp, $dir.'/'.$name.'.json')) {
            throw new RuntimeException("não foi possível gravar o pedido do navegador em {$dir}");
        }
    }
}
