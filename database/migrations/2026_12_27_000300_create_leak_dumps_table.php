<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Um arquivo da pasta de vazamentos que o admin mandou carregar: onde estava, o que rendeu e se foi apagado.
 *
 * Existe porque sem ela a tela fica cega. "Varrer a pasta e carregar" enfileira um job por arquivo,
 * e o admin precisa ver o que está na fila, o que terminou, com quantas credenciais, e o que falhou
 * e por quê — sem ler log do worker.
 *
 * O índice único é parcial, só sobre `queued`/`running`: impede enfileirar o mesmo arquivo duas
 * vezes, mas deixa recarregar depois (o mesmo nome pode voltar à pasta mais tarde).
 *
 * `removed` guarda se o .txt foi apagado da pasta. Apagar é escolha do admin (`leaks.delete_after_import`) e
 * nasce desligada: a pasta não tem backup, e um arquivo apagado por engano não volta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::create('leak_dumps', function (Blueprint $table) {
            $table->id();
            $table->string('path', 1024);
            $table->string('source', 64);
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('status', 8)->default('queued');
            $table->jsonb('tally')->nullable();
            $table->string('error', 200)->nullable();
            $table->boolean('removed')->default(false);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
        });

        DB::statement("alter table leak_dumps add constraint leak_dumps_status_check check (status in ('queued', 'running', 'done', 'failed'))");
        DB::statement("alter table leak_dumps add constraint leak_dumps_path_check check (path = btrim(path) and path <> '')");
        DB::statement("alter table leak_dumps add constraint leak_dumps_source_check check (source = btrim(source) and source <> '')");
        // Terminou (done/failed) tem hora de fim; quem está na fila não tem.
        DB::statement(<<<'SQL'
            alter table leak_dumps add constraint leak_dumps_finish_check check (
                (status in ('queued', 'running') and finished_at is null)
                or (status in ('done', 'failed') and finished_at is not null)
            )
        SQL);

        DB::statement("create unique index leak_dumps_active_path on leak_dumps (path) where status in ('queued', 'running')");
        DB::statement('create index leak_dumps_status on leak_dumps (status, id)');
        DB::statement('create index leak_dumps_requested_by on leak_dumps (requested_by)');
    }

    public function down(): void
    {
        Schema::dropIfExists('leak_dumps');
    }
};
