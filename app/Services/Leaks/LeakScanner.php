<?php

namespace App\Services\Leaks;

use App\Jobs\ImportLeakDump;
use App\Models\LeakDump;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Olha a pasta de vazamentos e enfileira os .txt que ainda não foram carregados.
 *
 * A raiz é sempre a mesma (o disco `leaks`, em storage/app/private/vazamentos); o que o admin
 * escolhe na tela é a SUBPASTA dentro dela (`leaks.prefix`, vazio = raiz) e se o arquivo é
 * apagado depois de entrar no banco.
 *
 * A listagem é uma chamada só (`listContents`), com o tamanho vindo junto, e não um `size()` por
 * arquivo.
 */
final class LeakScanner
{
    public const DISK = 'leaks';

    public function prefix(): string
    {
        return trim((string) Settings::get('leaks.prefix'), '/');
    }

    public function deletesAfterImport(): bool
    {
        return Settings::bool('leaks.delete_after_import');
    }

    /**
     * O que está na pasta agora, com o que o banco já sabe de cada um.
     *
     * @return array{ok:bool,error:?string,prefix:string,files:array<int,array<string,mixed>>}
     */
    public function look(): array
    {
        $prefix = $this->prefix();

        try {
            $listing = Storage::disk(self::DISK)->getDriver()->listContents($prefix, false);
            $found = [];

            foreach ($listing as $item) {
                if (! $item->isFile() || ! str_ends_with(mb_strtolower($item->path()), '.txt')) {
                    continue;
                }

                $found[$item->path()] = $item->fileSize();
            }
        } catch (Throwable $e) {
            // Sem permissão de leitura, caminho que é arquivo e não pasta: a tela diz o motivo em
            // vez de mostrar pasta vazia, que seria indistinguível de "não tem arquivo".
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300), 'prefix' => $prefix, 'files' => []];
        }

        $known = LeakDump::query()
            ->whereIn('path', array_keys($found))
            ->orderByDesc('id')
            ->get()
            ->groupBy('path');

        $files = [];

        foreach ($found as $path => $bytes) {
            $last = $known->get($path)?->first();

            $files[] = [
                'path' => $path,
                'source' => LeakDump::sourceFromPath($path),
                'bytes' => $bytes,
                'status' => $last?->status,
                'tally' => $last?->tally,
                'busy' => in_array($last?->status, ['queued', 'running'], true),
            ];
        }

        return ['ok' => true, 'error' => null, 'prefix' => $prefix, 'files' => $files];
    }

    /**
     * O admin pediu: enfileira o que falta, até o teto da varredura.
     *
     * Já carregado antes não volta à fila sozinho — recarregar é botão à parte. O que importa aqui é
     * que apertar "varrer" duas vezes não duplique trabalho.
     *
     * @return array{ok:bool,error:?string,encontrados:int,enfileirados:int,ja_na_fila:int,ja_carregados:int}
     */
    public function scan(User $admin): array
    {
        $look = $this->look();

        if (! $look['ok']) {
            return ['ok' => false, 'error' => $look['error'], 'encontrados' => 0, 'enfileirados' => 0, 'ja_na_fila' => 0, 'ja_carregados' => 0];
        }

        $limit = max(1, (int) config('secmon.leaks.max_files_per_scan'));
        $queued = $busy = $done = 0;

        foreach ($look['files'] as $file) {
            if ($file['busy']) {
                $busy++;

                continue;
            }

            if ($file['status'] === 'done') {
                $done++;

                continue;
            }

            if ($queued >= $limit) {
                break;
            }

            if ($this->enqueue($file, $admin)) {
                $queued++;
            } else {
                $busy++;
            }
        }

        return [
            'ok' => true, 'error' => null,
            'encontrados' => count($look['files']),
            'enfileirados' => $queued, 'ja_na_fila' => $busy, 'ja_carregados' => $done,
        ];
    }

    /** Enfileira um arquivo, mesmo que já tenha sido carregado antes (o botão "carregar de novo"). */
    public function enqueueAgain(string $path, User $admin): bool
    {
        return $this->enqueue([
            'path' => $path,
            'source' => LeakDump::sourceFromPath($path),
            'bytes' => null,
        ], $admin);
    }

    /** @param array<string,mixed> $file */
    private function enqueue(array $file, User $admin): bool
    {
        try {
            $dump = LeakDump::create([
                'path' => $file['path'],
                'source' => $file['source'],
                'bytes' => $file['bytes'],
                'status' => 'queued',
                'requested_by' => $admin->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // O índice parcial (queued/running) barrou: outra varredura já pegou este arquivo.
            return false;
        }

        ImportLeakDump::dispatch($dump->id);

        return true;
    }
}
