<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matéria fora do feed público, sem apagar. O estado é o `is_published` falso,
 * que a busca, os alertas, a fusão noturna e o resumo diário já respeitam;
 * estas colunas dizem desde quando e por quê (NewsCase::HIDDEN_REASONS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->timestampTz('hidden_at')->nullable();
            $table->string('hidden_reason', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn(['hidden_at', 'hidden_reason']);
        });
    }
};
