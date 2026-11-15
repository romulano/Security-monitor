<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chaves expostas, fora da stack de testes: tudo preso ao domínio comprovado (`owned_domains`), a
 * única exigência para varrer.
 *
 * - `key_sites`: o domínio comprovado que a conta ativou para a varredura de chaves (um por domínio,
 *   também na empresa): ativo ou pausado, o estado da varredura (`idle`, `queued`, `running`), o
 *   resultado da última (`ok`, `partial`, `failed`, com o resumo só de contagens e o erro curto) e a
 *   próxima automática (semanal).
 * - `exposed_keys`: uma linha por chave e domínio comprovado, como `pentest_keys` (a chave só
 *   mascarada, o nome, os lugares da última vez que apareceu e a impressão digital), venha ela da
 *   varredura (`scan`) ou do mapeamento dos testes (`pentest`). A decisão do dono (`allowed`,
 *   `leak`) fica de uma vez para outra.
 *
 * As linhas de `pentest_keys` vêm junto, pelo domínio comprovado da conta com o mesmo nome; a
 * tabela antiga fica até a PR que a apaga (o código no ar durante o deploy ainda escreve nela).
 * Tabelas novas: compatíveis com o código no ar. As FKs têm índice que começa por elas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::create('key_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owned_domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 8)->default('active');
            $table->string('scan_status', 8)->default('idle');
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('last_scan_at')->nullable();
            $table->string('last_result', 8)->nullable();
            $table->jsonb('last_summary')->nullable();
            $table->string('last_error', 200)->nullable();
            $table->timestampTz('next_scan_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("alter table key_sites add constraint key_sites_status_check check (status in ('active', 'paused'))");
        DB::statement("alter table key_sites add constraint key_sites_scan_status_check check (scan_status in ('idle', 'queued', 'running'))");
        DB::statement("alter table key_sites add constraint key_sites_last_result_check check (last_result in ('ok', 'partial', 'failed'))");
        DB::statement('create unique index key_sites_owned_domain on key_sites (owned_domain_id)');
        DB::statement('create index key_sites_user on key_sites (user_id)');
        DB::statement("create index key_sites_due on key_sites (next_scan_at) where status = 'active'");

        Schema::create('exposed_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owned_domain_id')->constrained()->cascadeOnDelete();
            $table->char('fingerprint', 32);
            $table->string('kind', 32);
            $table->string('masked', 80);
            $table->string('name', 64)->nullable();
            $table->jsonb('locations');
            $table->unsignedInteger('occurrences')->default(1);
            $table->string('source', 8);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->string('verdict', 8)->nullable();
            $table->foreignId('verdict_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verdict_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("alter table exposed_keys add constraint exposed_keys_source_check check (source in ('scan', 'pentest'))");
        DB::statement("alter table exposed_keys add constraint exposed_keys_verdict_check check (verdict in ('allowed', 'leak'))");
        DB::statement("alter table exposed_keys add constraint exposed_keys_fingerprint_check check (fingerprint ~ '^[0-9a-f]{32}$')");
        DB::statement('create unique index exposed_keys_domain_fingerprint on exposed_keys (owned_domain_id, fingerprint)');
        DB::statement('create index exposed_keys_verdict_by on exposed_keys (verdict_by)');

        // O que o mapeamento dos testes já achou: no domínio comprovado da conta (pessoal: a stack fica fora da empresa).
        DB::statement(<<<'SQL'
            insert into exposed_keys (owned_domain_id, fingerprint, kind, masked, name, locations, occurrences, source, first_seen_at,
                                      last_seen_at, verdict, verdict_by, verdict_at, created_at, updated_at)
            select o.id, k.fingerprint, k.kind, k.masked, k.name, k.locations, k.occurrences, 'pentest', k.first_seen_at,
                   k.last_seen_at, k.verdict, k.verdict_by, k.verdict_at, k.created_at, k.updated_at
            from pentest_keys k
            join pentest_domains d on d.id = k.pentest_domain_id
            join owned_domains o on o.user_id = k.user_id and o.domain = d.host
            on conflict (owned_domain_id, fingerprint) do nothing
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('exposed_keys');
        Schema::dropIfExists('key_sites');
    }
};
