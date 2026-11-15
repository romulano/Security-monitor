<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sugestões pelo DNS público no passo do domínio (Zoho no MX, SendGrid no SPF):
 * o que foi achado e o que o cliente decidiu sobre cada uma, para a mesma
 * sugestão não voltar depois de dispensada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_domains', function (Blueprint $table) {
            $table->json('dns_suggestions')->nullable(); // [{slug, name, evidence, status: pending|added|dismissed}]
            $table->timestampTz('dns_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monitored_domains', function (Blueprint $table) {
            $table->dropColumn(['dns_suggestions', 'dns_checked_at']);
        });
    }
};
