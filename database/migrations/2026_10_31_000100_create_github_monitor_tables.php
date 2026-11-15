<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoramento do GitHub: uma vez por dia, a API do GitHub procura em código
 * e repositórios públicos o que só deveria existir dentro da empresa (nomes
 * de chaves, endereços internos, repositórios privados) e o que imita a marca.
 *
 * - `github_monitors`: a entrevista do cliente (um por cliente) e o estado da varredura;
 * - `github_findings`: cada arquivo ou repositório achado, com as duas etapas da IA
 *   (classificação por nível e parecer de envio) e a avaliação do cliente.
 *
 * O valor de uma chave nunca é guardado: o trecho do arquivo fica com os segredos mascarados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // draft | active | paused
            $table->string('status', 16)->default('draft');
            $table->smallInteger('step_reached')->default(1);
            $table->timestampTz('completed_at')->nullable();

            // Entrevista
            $table->jsonb('orgs')->default('[]');
            $table->jsonb('private_repos')->default('[]');
            $table->jsonb('public_repos')->default('[]');
            $table->jsonb('key_names')->default('[]');
            $table->jsonb('token_prefixes')->default('[]');
            $table->jsonb('hostnames')->default('[]');
            $table->jsonb('email_domains')->default('[]');
            $table->jsonb('brands')->default('[]');
            $table->jsonb('checks')->default('[]');
            $table->string('min_level', 12)->default('high');

            // "Adicional": texto livre que a IA interpreta (só quando preenchido).
            $table->text('extra_text')->nullable();
            $table->string('extra_hash', 64)->nullable();
            // pending | done | failed | flagged | off
            $table->string('extra_status', 16)->nullable();
            $table->jsonb('extra_result')->default('{}');
            $table->text('extra_error')->nullable();
            $table->timestampTz('extra_at')->nullable();

            // Varredura
            $table->jsonb('known_repos')->default('{}');
            // pending | running | done | failed
            $table->string('scan_status', 16)->default('pending');
            $table->timestampTz('last_scan_at')->nullable();
            $table->timestampTz('next_scan_at')->nullable()->index();
            $table->timestampTz('manual_scan_at')->nullable();
            $table->jsonb('stats')->default('{}');
            $table->jsonb('last_log')->default('[]');
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });

        Schema::create('github_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('github_monitors')->cascadeOnDelete();
            $table->string('fingerprint', 64);
            // key_leak | token_prefix | sensitive_file | email_leak | hostname_leak | extra_term
            // | private_repo | org_new_repo | brand_repo
            $table->string('kind', 24);
            $table->jsonb('terms')->default('[]');
            $table->string('repo');
            $table->string('repo_owner')->nullable();
            $table->string('repo_owner_type', 16)->nullable();
            $table->text('repo_description')->nullable();
            $table->boolean('repo_fork')->default(false);
            $table->integer('repo_stars')->default(0);
            $table->timestampTz('repo_created_at')->nullable();
            $table->timestampTz('repo_pushed_at')->nullable();
            $table->text('path')->nullable();
            $table->text('html_url');
            // Trecho do arquivo com os segredos mascarados, e o resumo de cada um (tamanho, entropia, se parece exemplo).
            $table->text('fragment')->nullable();
            $table->jsonb('secrets')->default('[]');
            $table->string('content_hash', 64)->nullable();
            $table->jsonb('signals')->default('[]');
            // Nível pelas regras, usado quando a classificação da IA está desligada ou fora do ar.
            $table->string('auto_level', 12);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('changed_at')->nullable();
            $table->timestampTz('gone_at')->nullable();

            // Etapa 1: classificação (critical | high | medium | low | none), com a certeza de ser um achado real.
            $table->string('triage_level', 12)->nullable();
            $table->smallInteger('triage_score')->nullable();
            $table->text('triage_reason')->nullable();
            $table->timestampTz('triage_at')->nullable();

            // Etapa 2: parecer de envio (chance de o cliente querer o aviso).
            $table->smallInteger('ai_score')->nullable();
            $table->text('ai_reason')->nullable();
            $table->timestampTz('ai_reviewed_at')->nullable();

            // triage | discarded | below_level | ai_review | held | queued | sent | skipped | failed
            $table->string('status', 16)->default('triage')->index();
            $table->string('note', 32)->nullable();
            $table->timestampTz('notified_at')->nullable();

            // Avaliação do cliente, como nos alertas de notícia.
            $table->string('feedback', 12)->nullable();
            $table->timestampTz('feedback_at')->nullable();
            $table->text('feedback_note')->nullable();
            $table->boolean('feedback_note_flagged')->default(false);
            $table->boolean('feedback_unaffected')->default(false);
            $table->timestampsTz();

            $table->unique(['monitor_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_findings');
        Schema::dropIfExists('github_monitors');
    }
};
