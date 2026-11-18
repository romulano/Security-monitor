<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Descartar passa a APAGAR a linha, em vez de guardá-la como `discarded`.
 *
 * A fila enchia de banner do próprio dump ("CloudMarket VIP — ULP", "Fresh Premium ULP Drop", uma
 * régua de `====`): linha que nunca foi credencial e nunca vai ser. Guardar o descarte não serve a
 * ninguém — não é histórico de decisão, é lixo que o admin já disse que é lixo.
 *
 * Some o estado `discarded` e, com ele, a aba Descartadas. Sobram dois estados: `pending` (espera
 * decisão) e `resolved` (virou credencial, e o `leaked_credential_id` aponta para qual).
 *
 * As linhas já descartadas são apagadas aqui. Não há o que preservar: a credencial que alguma delas
 * tivesse gerado estaria em `resolved`, não em `discarded`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        DB::table('leak_import_lines')->where('status', 'discarded')->delete();

        DB::statement('alter table leak_import_lines drop constraint leak_import_lines_status_check');
        DB::statement("alter table leak_import_lines add constraint leak_import_lines_status_check check (status in ('pending', 'resolved'))");
    }

    public function down(): void
    {
        DB::statement('alter table leak_import_lines drop constraint leak_import_lines_status_check');
        DB::statement("alter table leak_import_lines add constraint leak_import_lines_status_check check (status in ('pending', 'resolved', 'discarded'))");
    }
};
