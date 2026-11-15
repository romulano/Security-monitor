<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links assinados de uso único que não deixam rastro na própria linha: o
 * motivo escrito pela página que os botões da mensagem abrem (alerta, Radar,
 * GitHub). Um registro por item e finalidade; a restrição única segura dois
 * envios ao mesmo tempo. O voto não passa por aqui: a chave dele é o próprio
 * voto gravado no item.
 *
 * Só uma tabela nova: o código anterior no ar não a vê.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signed_link_uses', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('purpose', 20);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['subject_type', 'subject_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signed_link_uses');
    }
};
