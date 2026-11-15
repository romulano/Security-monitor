<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O motivo, em linguagem clara, dos erros de análise que o mapa fixo
 * (ErrorReasons) não conhece, escrito pelo hermes uma vez por assinatura do erro
 * (a mensagem sem números nem ids): o mesmo erro em outro artigo não chama a IA
 * de novo. `pending` (na fila), `done` ou `failed` (a tela mostra o texto técnico).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::create('error_explanations', function (Blueprint $table) {
            $table->id();
            $table->char('signature_hash', 40)->unique();
            $table->string('signature', 300);
            $table->string('status', 8)->default('pending');
            $table->string('explanation', 400)->nullable();
            $table->string('error', 300)->nullable();
            $table->timestampsTz();
        });

        DB::statement("alter table error_explanations add constraint error_explanations_status_check check (status in ('pending', 'done', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('error_explanations');
    }
};
