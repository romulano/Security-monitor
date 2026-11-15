<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plano empresarial, E3d (DESENHO-5.3 §4.2): o aviso de limite em 80% e 100% sai uma vez por marco. Cada linha é um
 * marco já avisado: o limite (`domains`, depois `subdomains` e `radar_seeds`), o escopo (`scope_id` 0 = a empresa; o
 * `owned_domain_id` nos subdomínios) e o nível. Ao voltar abaixo do marco, a linha sai e o aviso pode sair de novo.
 * Tabela nova: o código no ar não a vê.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_limit_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('limit', 24);
            // 0 = a empresa toda (não nulo: o único do Postgres não junta nulos).
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->unsignedSmallInteger('level');
            $table->timestampTz('sent_at');

            // Também é o índice da FK company_id.
            $table->unique(['company_id', 'limit', 'scope_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_limit_notices');
    }
};
