<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das tentativas de análise de cada artigo (Admin → Coletas): quando, o
 * gatilho (coleta, retentar, refazer com a IA, refazer a matéria ou fusão), o driver,
 * o resultado (`ok`, `heuristica` ou `erro`) e o erro técnico. A poda é a do
 * secmon:prune (secmon.retention.article_attempts_days).
 *
 * E limpa o erro velho que a repetição deixava (02/11, artigo 2419): artigo
 * analisado por uma IA (enricher_driver não é a heurística) com erro de driver
 * ficou com o erro da tentativa anterior. O erro vai para o histórico, com a
 * data da coleta, e sai do artigo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::create('article_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 16);
            $table->string('driver', 16)->nullable();
            $table->string('result', 12);
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("alter table article_attempts add constraint article_attempts_trigger_check check (trigger in ('coleta', 'retentar', 'refazer-ia', 'refazer-materia', 'fusao'))");
        DB::statement("alter table article_attempts add constraint article_attempts_result_check check (result in ('ok', 'heuristica', 'erro'))");
        DB::statement('create index article_attempts_article on article_attempts (article_id, id)');
        DB::statement('create index article_attempts_created on article_attempts (created_at)');

        $stale = "error is not null and enricher_driver in ('claude', 'openai', 'hermes')
            and (error like 'hermes: %' or error like 'claude: %' or error like 'openai: %' or error like 'Driver %')";

        DB::statement("insert into article_attempts (article_id, trigger, driver, result, error, created_at)
            select id, 'coleta', left(case when error like 'Driver %' then split_part(error, ' ', 2) else split_part(error, ':', 1) end, 16), 'heuristica', error, coalesce(collected_at, now())
            from articles where {$stale}");
        DB::statement("update articles set error = null where {$stale}");
    }

    public function down(): void
    {
        Schema::dropIfExists('article_attempts');
    }
};
