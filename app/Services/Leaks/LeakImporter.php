<?php

namespace App\Services\Leaks;

use App\Models\LeakedCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Lê um dump da pasta de vazamentos (o disco `leaks`, em storage/) linha por linha e grava o que interessa.
 *
 * O arquivo tem gigabytes, então nada é carregado inteiro: é um `readStream` com `fgets`, e as
 * linhas saem em lotes de mil. Cada linha vai para um de quatro destinos:
 *
 * - credencial de EMPRESA  → `leaked_credentials` (`insertOrIgnore`: recarregar o dump não duplica)
 * - webmail genérico       → descartada, só contada (a lista é de Admin → Vazamentos)
 * - login que não é e-mail → descartada, só contada (sem domínio não há empresa a avisar)
 * - ordem não comprovável  → `leak_import_lines`, para um humano decidir
 *
 * A contagem volta inteira de propósito: descarte silencioso sem número é indistinguível de bug.
 */
final class LeakImporter
{
    private const BATCH = 1000;

    public function __construct(private CredentialLineParser $parser) {}

    /**
     * @param  callable(array<string,int>):void|null  $onProgress  chamado a cada lote
     * @return array<string,int>
     */
    public function import(string $source, string $path, string $disk = 'leaks', ?string $leakedAt = null, ?callable $onProgress = null): array
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Não consegui abrir {$path} no disco {$disk}.");
        }

        $tally = [
            'linhas' => 0, 'salvas' => 0, 'genericas' => 0, 'sem_email' => 0,
            'humano' => 0, 'vazias' => 0, 'repetidas' => 0,
        ];
        $credentials = [];
        $quarantine = [];
        $now = now();
        $lineNo = 0;

        try {
            while (($raw = fgets($stream)) !== false) {
                $lineNo++;
                $parsed = $this->parser->parse($raw);

                if ($parsed['reason'] === 'vazia') {
                    $tally['vazias']++;

                    continue;
                }

                $tally['linhas']++;

                if (! $parsed['ok']) {
                    $tally['humano']++;
                    $quarantine[] = [
                        'source' => $source,
                        'line_no' => $lineNo,
                        'raw' => mb_substr(rtrim($raw, "\r\n"), 0, 2000),
                        'reason' => $parsed['reason'],
                        'country' => $parsed['country'],
                        'fields' => json_encode(array_map(fn ($f) => mb_substr($f, 0, 400), $parsed['fields']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'fingerprint' => md5(rtrim($raw, "\r\n")),
                        'status' => 'pending',
                        'created_at' => $now,
                    ];
                } elseif ($parsed['corporate']) {
                    $tally['salvas']++;
                    $credentials[] = [
                        'domain' => $parsed['domain'],
                        'email' => $parsed['email'],
                        'password' => $parsed['password'],
                        'url' => $parsed['url'],
                        'site_host' => $parsed['site_host'],
                        'country' => $parsed['country'],
                        'source' => $source,
                        'fingerprint' => LeakedCredential::fingerprintFor($parsed['url'], $parsed['username'], $parsed['password']),
                        'leaked_at' => $leakedAt,
                        'created_at' => $now,
                    ];
                } elseif ($parsed['free_mail']) {
                    $tally['genericas']++;
                } else {
                    $tally['sem_email']++;
                }

                if (count($credentials) >= self::BATCH || count($quarantine) >= self::BATCH) {
                    $this->flush($credentials, $quarantine, $tally);
                    $onProgress === null || $onProgress($tally);
                }
            }
        } finally {
            fclose($stream);
        }

        $this->flush($credentials, $quarantine, $tally);

        return $tally;
    }

    /**
     * `insertOrIgnore` vira `on conflict do nothing` no Postgres: o par repetido dentro do mesmo
     * dump cai fora pelo índice único, e recarregar o arquivo é inofensivo.
     *
     * @param  array<int,array<string,mixed>>  $credentials
     * @param  array<int,array<string,mixed>>  $quarantine
     * @param  array<string,int>  $tally
     */
    private function flush(array &$credentials, array &$quarantine, array &$tally): void
    {
        if ($credentials !== []) {
            $wanted = count($credentials);
            $written = DB::table('leaked_credentials')->insertOrIgnore($credentials);
            $tally['repetidas'] += $wanted - $written;
            $tally['salvas'] -= $wanted - $written;
            $credentials = [];
        }

        if ($quarantine !== []) {
            $wanted = count($quarantine);
            $written = DB::table('leak_import_lines')->insertOrIgnore($quarantine);
            $tally['humano'] -= $wanted - $written;
            $quarantine = [];
        }
    }
}
