<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O que a etapa "Post no TabNews" decidiu sobre cada matéria: uma linha por
 * matéria, para nenhuma ser avaliada (e cobrada da IA) duas vezes. `posted`
 * com o endereço do post, `rejected` com o motivo da IA (ou do filtro do
 * texto) e `failed` quando o TabNews recusou. A matéria apagada pela fusão
 * noturna deixa a linha, sem o vínculo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::create('tabnews_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->nullable()->unique()->constrained('cases')->nullOnDelete();
            $table->string('decision', 10);
            $table->string('reason', 500)->nullable();
            $table->string('title', 255)->nullable();
            $table->string('url', 400)->nullable();
            $table->timestampsTz();

            $table->index(['decision', 'created_at']);
        });

        DB::statement("alter table tabnews_posts add constraint tabnews_posts_decision_check check (decision in ('posted', 'rejected', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tabnews_posts');
    }
};
