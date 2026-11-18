<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quarentena da carga de vazamentos: as linhas que o parser se RECUSOU a adivinhar.
 *
 * O arquivo não tem formato fixo — `url:login:senha`, `login:senha:url`, `senha:login:url` — e
 * quando nada na linha prova a ordem, chutar grava senha no lugar de login. Então a linha vem para
 * cá inteira, com o motivo e com os campos já partidos, e um humano decide em Admin → Vazamentos.
 * Só o admin vê e mexe (rota sob `auth` + `admin`).
 *
 * - `reason`: o que o parser não conseguiu provar. `ordem_ambigua` é o caso central: endereço no
 *   fim e nenhum dos outros dois campos é e-mail, então login e senha são intercambiáveis.
 * - `fields`: o que o parser partiu, para a tela já vir preenchida em vez de o humano recortar à mão.
 * - `status`: `pending` até alguém decidir; vira `resolved` (gerou credencial) ou `discarded` (lixo).
 * - `leaked_credential_id`: a linha que o humano criou, quando criou. Fica para auditoria.
 *
 * Único por (`source`, `fingerprint`): recarregar o mesmo dump não enfileira a mesma linha de novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::create('leak_import_lines', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64);
            $table->unsignedInteger('line_no');
            $table->string('raw', 2000);
            $table->string('reason', 24);
            $table->char('country', 2)->nullable();
            $table->jsonb('fields');
            $table->char('fingerprint', 32);
            $table->string('status', 10)->default('pending');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('leaked_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("alter table leak_import_lines add constraint leak_import_lines_status_check check (status in ('pending', 'resolved', 'discarded'))");
        DB::statement("alter table leak_import_lines add constraint leak_import_lines_fingerprint_check check (fingerprint ~ '^[0-9a-f]{32}$')");
        DB::statement("alter table leak_import_lines add constraint leak_import_lines_source_check check (source = btrim(source) and source <> '')");
        DB::statement(<<<'SQL'
            alter table leak_import_lines add constraint leak_import_lines_reason_check check (
                reason in ('campos_de_menos', 'campos_demais', 'sem_endereco', 'dois_enderecos',
                           'ordem_ambigua', 'campo_vazio', 'campo_longo')
            )
        SQL);
        // Resolvida tem quem resolveu e quando; pendente não tem nem um nem outro.
        DB::statement(<<<'SQL'
            alter table leak_import_lines add constraint leak_import_lines_resolution_check check (
                (status = 'pending' and resolved_at is null and leaked_credential_id is null)
                or (status <> 'pending' and resolved_at is not null)
            )
        SQL);

        DB::statement('create unique index leak_import_lines_source_fingerprint on leak_import_lines (source, fingerprint)');
        // A tela abre nas pendentes, mais velhas primeiro: o índice parcial serve a ela direto.
        DB::statement("create index leak_import_lines_pending on leak_import_lines (id) where status = 'pending'");
        DB::statement('create index leak_import_lines_resolved_by on leak_import_lines (resolved_by)');
        DB::statement('create index leak_import_lines_credential on leak_import_lines (leaked_credential_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('leak_import_lines');
    }
};
