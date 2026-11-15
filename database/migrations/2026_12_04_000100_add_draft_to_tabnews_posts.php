<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O rascunho do post do TabNews, para a avaliação da IA não esperar a vaga de
 * publicação: a matéria é avaliada assim que assenta e, se vale um post, o
 * texto aprovado fica guardado aqui com a decisão `queued` até o intervalo
 * entre posts liberar. Então outro job publica o que está guardado, sem chamar
 * a IA de novo.
 *
 * Só acrescenta duas colunas que aceitam nulo e um valor novo na lista das
 * decisões: o código anterior no ar durante a troca continua funcionando (ele
 * nunca grava `queued` nem lê as colunas novas).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::table('tabnews_posts', function (Blueprint $table) {
            $table->text('body')->nullable()->after('subject');
            $table->string('source_url', 400)->nullable()->after('body');
        });

        DB::statement('alter table tabnews_posts drop constraint tabnews_posts_decision_check');
        DB::statement("alter table tabnews_posts add constraint tabnews_posts_decision_check check (decision in ('posted', 'rejected', 'failed', 'queued'))");
    }

    public function down(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        // Rascunho que não saiu volta a ser avaliado pela IA no código anterior.
        DB::table('tabnews_posts')->where('decision', 'queued')->delete();

        DB::statement('alter table tabnews_posts drop constraint tabnews_posts_decision_check');
        DB::statement("alter table tabnews_posts add constraint tabnews_posts_decision_check check (decision in ('posted', 'rejected', 'failed'))");

        Schema::table('tabnews_posts', function (Blueprint $table) {
            $table->dropColumn(['body', 'source_url']);
        });
    }
};
