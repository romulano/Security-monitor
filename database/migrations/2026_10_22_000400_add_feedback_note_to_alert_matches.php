<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O motivo (opcional) que o cliente escreve ao avaliar um alerta, lido pela IA
 * do parecer. `feedback_note_flagged` marca o texto com cara de instrução para
 * a IA: fica guardado, mas não vai para o modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_matches', function (Blueprint $table) {
            $table->string('feedback_note', 280)->nullable();
            $table->boolean('feedback_note_flagged')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('alert_matches', function (Blueprint $table) {
            $table->dropColumn(['feedback_note', 'feedback_note_flagged']);
        });
    }
};
