<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Relevante, mas não nos atinge": a notícia importava, mas o cliente já estava
 * protegido (corrigido, sem a versão afetada, mitigado). Só vale com voto relevante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_matches', function (Blueprint $table) {
            $table->boolean('feedback_unaffected')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('alert_matches', function (Blueprint $table) {
            $table->dropColumn('feedback_unaffected');
        });
    }
};
