<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parecer da IA antes do envio: quanto (0–100) a notícia interessa ao
 * cliente, pelo perfil e pelas avaliações dele, e a frase que explica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_matches', function (Blueprint $table) {
            $table->unsignedTinyInteger('ai_relevance')->nullable();
            $table->string('ai_reason', 500)->nullable();
            $table->timestampTz('ai_reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alert_matches', function (Blueprint $table) {
            $table->dropColumn(['ai_relevance', 'ai_reason', 'ai_reviewed_at']);
        });
    }
};
