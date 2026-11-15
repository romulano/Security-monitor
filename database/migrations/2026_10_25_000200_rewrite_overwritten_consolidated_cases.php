<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Até aqui, cada artigo novo trocava o relatório da matéria pela análise dele
 * sozinho. Matéria da fusão noturna que recebeu fonte depois de consolidada
 * perdeu o relatório que juntava as outras (a do php2shell virou o boletim da
 * Palo Alto). Sem `consolidated_at`, a próxima fusão a reescreve do zero com
 * todas as fontes; dali em diante, cada fonte nova só a enriquece.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('cases')
            ->whereNotNull('consolidated_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('articles')
                ->whereColumn('articles.case_id', 'cases.id')
                ->where('articles.status', 'enriched')
                ->whereColumn('articles.collected_at', '>', 'cases.consolidated_at'))
            ->update(['consolidated_at' => null]);
    }

    public function down(): void
    {
        // O relatório antigo não volta: a reescrita da fusão já o substituiu.
    }
};
