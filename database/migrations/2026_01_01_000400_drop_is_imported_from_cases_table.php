<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A coluna existia para separar os casos trazidos de uma API externa dos que os
 * bots coletaram. Agora todo caso vem do crawler, então a distinção não existe
 * mais e a flag só confundiria quem lesse o schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn('is_imported');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->boolean('is_imported')->default(false)->index();
        });
    }
};
