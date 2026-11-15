<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os avisos do Radar sobre um subdomínio da empresa (E4a, DESENHO-5.3 §5.2): a tomada (CNAME para fora com destino que
 * não resolve) e a mudança para fora da zona. Tabela nova: o `lookalike_alerts` exige um domínio parecido (FK NOT NULL),
 * e mexer nele seria outra PR. Sai do jeito do aviso do Radar (canais de quem criou, DeliveryGate), com o mesmo status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subdomain_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owned_subdomain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('watch_id')->constrained('lookalike_watches')->cascadeOnDelete();
            $table->string('kind', 12);
            $table->string('risk', 10);
            $table->string('status', 12)->default('pending');
            $table->string('note', 32)->nullable();
            $table->jsonb('facts')->default('{}');
            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();

            // Também é o índice da FK owned_subdomain_id.
            $table->index(['owned_subdomain_id', 'kind']);
            $table->index('watch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subdomain_alerts');
    }
};
