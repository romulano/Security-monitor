<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoria de segurança dos sites do admin (Admin → Segurança).
 *
 * - security_sites: o site (host) que o admin cadastrou, com o aceite e a posse comprovada.
 * - security_checks: uma linha por varredura, com a nota e o resultado de cada verificação
 *   (só estado e valores curtos: nunca o corpo da página, valor de cookie ou certificado inteiro).
 * - security_findings: o estado ATUAL de cada problema (aberto/resolvido), para saber o que
 *   é novo, o que piorou e o que foi corrigido sem recalcular o histórico.
 * - security_events: só as mudanças de estado; é daqui que sai "o que piorou".
 *
 * Tabelas novas: compatível com o código anterior no ar. Toda FK tem índice que começa pela
 * coluna dela (ForeignKeyIndexTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_sites', function (Blueprint $table) {
            $table->id();
            // O admin que cadastrou (o monitor é do admin; cliente não cadastra).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('host', 253);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('interval_minutes')->default(360);
            // Declaração "sou o responsável por este site", com data e IP de quem declarou.
            $table->timestampTz('consent_at');
            $table->string('consent_ip', 45)->nullable();
            // Posse: server (o host aponta só para o servidor do Security Monitor) ou txt (secmon-verify=<token>).
            $table->string('verify_token', 40);
            $table->string('ownership', 12)->nullable();
            $table->timestampTz('ownership_checked_at')->nullable();
            $table->timestampTz('next_check_at')->nullable();
            $table->timestampTz('last_check_at')->nullable();
            $table->timestampTz('manual_check_at')->nullable();
            $table->string('last_status', 16)->nullable(); // ok | aviso | critico | erro
            $table->unsignedSmallInteger('last_score')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'host']);
        });

        DB::statement('create index security_sites_due_index on security_sites (next_check_at) where enabled');

        Schema::create('security_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('security_sites')->cascadeOnDelete();
            $table->timestampTz('started_at');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('status', 16); // ok | aviso | critico | erro
            $table->unsignedSmallInteger('score')->default(0);
            $table->timestampTz('tls_not_after')->nullable();
            $table->string('tls_protocol', 16)->nullable();
            // {verificação: {s: estado, v: valor curto}}. Sem corpo de resposta, cookie ou certificado.
            $table->jsonb('results')->default('{}');
            $table->string('fingerprint', 40)->nullable();
            $table->string('error', 300)->nullable();

            $table->index(['site_id', 'started_at']);
        });

        Schema::create('security_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('security_sites')->cascadeOnDelete();
            $table->string('code', 40);
            // O que distingue duas ocorrências do mesmo problema (o nome do cookie, o caminho exposto).
            $table->string('subject', 120)->default('');
            $table->string('severity', 10); // aviso | critico
            $table->string('status', 10)->default('open'); // open | resolved
            $table->string('evidence', 300)->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('resolved_at')->nullable();

            $table->unique(['site_id', 'code', 'subject']);
        });

        DB::statement("create index security_findings_open_index on security_findings (site_id) where status = 'open'");

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('security_sites')->cascadeOnDelete();
            $table->foreignId('finding_id')->nullable()->constrained('security_findings')->nullOnDelete();
            $table->string('kind', 12); // opened | worsened | resolved | reopened
            $table->string('code', 40);
            $table->string('detail', 300)->nullable();
            $table->timestampTz('created_at');

            $table->index(['site_id', 'created_at']);
        });

        DB::statement('create index security_events_finding_index on security_events (finding_id) where finding_id is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('security_findings');
        Schema::dropIfExists('security_checks');
        Schema::dropIfExists('security_sites');
    }
};
