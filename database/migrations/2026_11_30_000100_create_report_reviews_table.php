<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O revisor do relatório (Admin → Prompts, "Revisor do relatório"): uma linha por
 * versão revisada do relatório de uma matéria (a que o artigo abriu ou atualizou).
 *
 * - `status`: `approved` (aprovado), `pending` (revisão pendente: o parecer final
 *   ainda reprovou, ou a revisão parou depois de pedir ajuste) ou `skipped` (o
 *   revisor não deu parecer: hermes fora, sem tempo, resposta inválida, análise sem IA).
 * - `rounds`: os pareceres, na ordem (o veredito, o escopo, os problemas com o campo,
 *   o trecho e a instrução). Nunca o texto inteiro do relatório: ele já está na matéria.
 * - `calls`: chamadas a mais ao hermes (pareceres e refações).
 *
 * Tabela nova: compatível com o código no ar. As FKs têm índice que começa por
 * elas (ForeignKeyIndexTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->nullable()->constrained('cases')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->string('origin', 8)->default('coleta');
            $table->string('mode', 8);
            $table->string('status', 8);
            $table->unsignedSmallInteger('adjustments')->default(0);
            $table->unsignedSmallInteger('calls')->default(0);
            $table->jsonb('rounds')->default('[]');
            $table->string('reason', 300)->nullable();
            $table->unsignedInteger('seconds')->default(0);
            $table->timestampsTz();
        });

        DB::statement("alter table report_reviews add constraint report_reviews_status_check check (status in ('approved', 'pending', 'skipped'))");
        DB::statement("alter table report_reviews add constraint report_reviews_mode_check check (mode in ('new', 'update'))");
        DB::statement("alter table report_reviews add constraint report_reviews_origin_check check (origin in ('coleta', 'refazer'))");
        DB::statement('create index report_reviews_case on report_reviews (case_id, id)');
        DB::statement('create index report_reviews_article on report_reviews (article_id)');
        DB::statement('create index report_reviews_recent on report_reviews (created_at, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_reviews');
    }
};
