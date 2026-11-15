<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tira do feed público, sem apagar, as matérias que vieram só do feed geral do
 * TechCrunch e que a IA marcou como low: resenha de celular, IA, propaganda de
 * evento (alguns resumos dizem "No security incident"). Em 2026-11-01 eram ~22
 * dos 100 casos mais recentes. Matéria com artigo de outro feed fica: outra
 * fonte achou o assunto relevante.
 *
 * Só o banco muda aqui. A busca solta as matérias no boot seguinte, logo depois
 * das migrations (secmon:es-setup, CaseIndex::pruneHidden); a página delas dá
 * 410 para o público, e o admin continua vendo e pode devolver ao feed.
 */
return new class extends Migration
{
    private const GENERAL = 'https://techcrunch.com/feed/';

    public function up(): void
    {
        $articles = fn ($q) => $q->selectRaw('1')->from('articles')->whereColumn('articles.case_id', 'cases.id');

        $hidden = DB::table('cases')
            ->where('is_published', true)
            ->where('criticity', 'low')
            ->whereExists($articles)
            ->whereNotExists(fn ($q) => $articles($q)->where(fn ($q) => $q
                ->whereNull('articles.feed_url')
                ->orWhere('articles.feed_url', '!=', self::GENERAL)))
            ->update(['is_published' => false, 'hidden_at' => now(), 'hidden_reason' => 'off_topic_feed', 'updated_at' => now()]);

        if ($hidden > 0) {
            Log::info('Matérias do feed geral do TechCrunch tiradas do feed público', ['casos' => $hidden]);
        }
    }

    public function down(): void
    {
        // Voltam ao feed no próximo secmon:reindex (o boot só tira da busca, não põe).
        DB::table('cases')
            ->where('hidden_reason', 'off_topic_feed')
            ->update(['is_published' => true, 'hidden_at' => null, 'hidden_reason' => null, 'updated_at' => now()]);
    }
};
