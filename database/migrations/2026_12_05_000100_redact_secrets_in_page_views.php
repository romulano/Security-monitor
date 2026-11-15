<?php

use App\Services\Monitoring\RecordedUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Os acessos gravados antes do RecordedUrl: o link de confirmação de e-mail
 * vira o modelo da rota, e a assinatura, o code do OAuth e as outras chaves
 * sensíveis da query (no caminho e no Referer) ficam só com o nome. Anda por
 * faixas de id, lê só as linhas que podem ter segredo e regrava, num UPDATE por
 * lote, só as que mudaram: rodar de novo não muda nada.
 *
 * O link que o container velho gravar durante o deploy fica para a próxima
 * migration que repete esta limpeza.
 */
return new class extends Migration
{
    private const RANGE = 50_000;

    private const BATCH = 300;

    public function up(): void
    {
        $names = implode('|', RecordedUrl::SENSITIVE);
        // A chave com o nome sensível (code[], user[password]) ou com o nome codificado (%73ignature); o
        // PHP decide no fim, e o que não muda não é regravado.
        $query = "[?&][^=&#]*({$names}|%)[^=&#]*=";
        // Hoje SECRET_PATHS está vazio (a confirmação de e-mail saiu com o login): sem prefixo nenhum para
        // casar, nunca um LIKE '%' que pegaria a tabela inteira.
        $confirm = array_key_first(RecordedUrl::SECRET_PATHS);
        [$min, $max] = [(int) DB::table('page_views')->min('id'), (int) DB::table('page_views')->max('id')];

        for ($from = $min; $from <= $max; $from += self::RANGE) {
            $changed = [];

            $rows = DB::table('page_views')
                ->whereBetween('id', [$from, $from + self::RANGE - 1])
                ->where(fn ($q) => $q->whereRaw('path ~* ?', [$query])
                    ->orWhereRaw('referer ~* ?', [$query])
                    ->when($confirm !== null, fn ($q) => $q
                        ->orWhereRaw('lower(path) like ?', [$confirm.'%'])
                        ->orWhereRaw('lower(referer) like ?', ['%'.$confirm.'%'])))
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
                    'update page_views as p set path = v.path, referer = v.referer'
                    .' from (values '.implode(', ', array_fill(0, count($batch), '(?::bigint, ?::text, ?::text)')).') as v(id, path, referer)'
                    .' where p.id = v.id',
                    array_merge(...$batch),
                );
            }
        }
    }

    public function down(): void
    {
        // O segredo apagado não volta.
    }
};
