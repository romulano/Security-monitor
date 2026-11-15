<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domínios semelhantes: nomes parecidos com o domínio verificado do cliente,
 * achados por variação do nome e por certificados públicos.
 *
 * - `lookalike_watches`: o monitoramento de um domínio verificado (um por domínio);
 * - `lookalike_domains`: cada nome parecido que existe no DNS, com o nível de risco;
 * - `lookalike_captures`: cada visita do navegador (docker/browser), com os prints.
 *   É o histórico que serve de prova na denúncia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookalike_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->unique()->constrained('monitored_domains')->cascadeOnDelete();
            $table->string('status')->default('active'); // active | paused
            // pending | running | done | failed
            $table->string('scan_status')->default('pending');
            $table->timestampTz('last_scan_at')->nullable();
            $table->timestampTz('next_scan_at')->nullable()->index();
            $table->timestampTz('manual_scan_at')->nullable();
            $table->jsonb('stats')->default('{}');
            // Variações perigosas que ainda ninguém registrou (sugestão de registro defensivo).
            $table->jsonb('free_variants')->default('[]');
            $table->jsonb('last_log')->default('[]');
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });

        Schema::create('lookalike_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watch_id')->constrained('lookalike_watches')->cascadeOnDelete();
            $table->string('host');
            $table->jsonb('techniques')->default('[]');
            $table->boolean('from_certificate')->default(false);
            $table->jsonb('ips')->default('[]');
            $table->jsonb('mx')->default('[]');
            $table->jsonb('ns')->default('[]');
            $table->timestampTz('registered_at')->nullable();
            $table->jsonb('rdap')->default('{}');
            $table->jsonb('hosting')->default('{}');
            // pending | no_site | offline | parked | blank | redirects_to_you | content
            $table->string('state')->default('pending');
            // critical | high | watch | low
            $table->string('risk')->default('watch')->index();
            $table->smallInteger('score')->default(0);
            $table->jsonb('signals')->default('[]');
            // O que o cliente disse: ours (é nosso) | safe (não é ameaça) | threat (é golpe)
            $table->string('verdict')->nullable();
            $table->timestampTz('verdict_at')->nullable();
            $table->string('previous_risk')->nullable();
            $table->timestampTz('risk_changed_at')->nullable();
            $table->unsignedBigInteger('last_capture_id')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('rdap_checked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['watch_id', 'host']);
        });

        Schema::create('lookalike_captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lookalike_id')->constrained('lookalike_domains')->cascadeOnDelete();
            $table->string('status')->default('queued')->index(); // queued | done | failed
            $table->string('batch', 40)->nullable()->index();
            $table->timestampTz('requested_at');
            $table->timestampTz('captured_at')->nullable();
            $table->text('final_url')->nullable();
            $table->smallInteger('http_status')->nullable();
            $table->text('title')->nullable();
            $table->text('text_excerpt')->nullable();
            $table->string('html_hash', 64)->nullable();
            $table->jsonb('data')->default('{}');
            $table->string('desktop_path')->nullable();
            $table->string('mobile_path')->nullable();
            // Situação e risco no momento da visita: a linha do tempo da página.
            $table->string('state')->nullable();
            $table->string('risk')->nullable();
            $table->smallInteger('score')->nullable();
            // Mudou de nível nesta visita: guardada para sempre, como prova.
            $table->boolean('keep')->default(false);
            $table->text('error')->nullable();
            $table->timestampsTz();
        });

        Schema::table('lookalike_domains', function (Blueprint $table) {
            $table->foreign('last_capture_id')->references('id')->on('lookalike_captures')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lookalike_domains', fn (Blueprint $table) => $table->dropForeign(['last_capture_id']));
        Schema::dropIfExists('lookalike_captures');
        Schema::dropIfExists('lookalike_domains');
        Schema::dropIfExists('lookalike_watches');
    }
};
