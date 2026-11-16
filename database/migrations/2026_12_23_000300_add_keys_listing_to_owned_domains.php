<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chaves expostas com lista própria: `added` (cadastrado direto lá; fica mesmo sem outro serviço usando o nome),
 * `removed` (herdado de outro serviço e tirado de Chaves) ou nulo (herdado, na lista). Coluna nova e nula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owned_domains', function (Blueprint $table) {
            $table->string('keys_listing', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('owned_domains', function (Blueprint $table) {
            $table->dropColumn('keys_listing');
        });
    }
};
