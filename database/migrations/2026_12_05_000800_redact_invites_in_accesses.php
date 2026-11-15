<?php

use App\Services\Monitoring\RecordedUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O convite do cadastro (`/cadastro?convite=...`) vale como senha até ser usado
 * e entrava inteiro no Admin → Acessos. Agora o RecordedUrl o grava só com o
 * nome; aqui sai o que já estava gravado, nos acessos e nos eventos, com a mesma
 * limpeza da 2026_12_05_000100 (que lê a lista de chaves do RecordedUrl). Rodar
 * de novo não muda nada.
 */
return new class extends Migration
{
    private const RANGE = 50_000;

    private const BATCH = 300;

    public function up(): void
    {
        foreach (['page_views', 'access_events'] as $table) {
            $this->clean($table);
        }
    }

    public function down(): void
    {
        // O segredo apagado não volta.
    }

    private function clean(string $table): void
    {
        $names = implode('|', RecordedUrl::SENSITIVE);
        $query = "[?&][^=&#]*({$names}|%)[^=&#]*=";
        [$min, $max] = [(int) DB::table($table)->min('id'), (int) DB::table($table)->max('id')];

        for ($from = $min; $from <= $max; $from += self::RANGE) {
            $changed = [];

            $rows = DB::table($table)
                ->whereBetween('id', [$from, $from + self::RANGE - 1])
                ->where(fn ($q) => $q->whereRaw('path ~* ?', [$query])->orWhereRaw('referer ~* ?', [$query]))
                ->get(['id', 'path', 'referer']);

            foreach ($rows as $row) {
                $path = mb_substr(RecordedUrl::sanitize($row->path), 0, 500);
                $referer = $row->referer === null ? null : mb_substr(RecordedUrl::sanitize($row->referer), 0, 500);

                if ($path !== $row->path || $referer !== $row->referer) {
                    $changed[] = [$row->id, $path, $referer];
                }
            }

            foreach (array_chunk($changed, self::BATCH) as $batch) {
                DB::update(
                    "update {$table} as t set path = v.path, referer = v.referer"
                    .' from (values '.implode(', ', array_fill(0, count($batch), '(?::bigint, ?::text, ?::text)')).') as v(id, path, referer)'
                    .' where t.id = v.id',
                    array_merge(...$batch),
                );
            }
        }
    }
};
