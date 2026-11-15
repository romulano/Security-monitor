<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitas que chegaram por um anúncio (Admin → Campanhas). O endereço do anúncio
 * traz utm_campaign e companhia; cada sessão que entra por ele vira uma linha, com
 * as páginas vistas depois, se abriu o cadastro e, se criou a conta, quem foi.
 * Sem IP e sem navegador: a sessão (o cookie que já existe) liga as páginas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_visits', function (Blueprint $table) {
            $table->id();
            $table->string('campaign', 100);
            $table->string('source', 50)->nullable();
            $table->string('medium', 50)->nullable();
            $table->string('term', 150)->nullable();
            $table->string('landing_path', 255);
            $table->unsignedInteger('pages')->default(1);
            $table->timestamp('reached_signup_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('signed_up_at')->nullable();
            $table->timestamps();

            $table->index(['campaign', 'created_at']);
            $table->index('created_at');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_visits');
    }
};
