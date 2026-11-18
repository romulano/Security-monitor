<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A busca da tela procura por domínio, e-mail e site. Os dois primeiros já tinham índice; o site
 * não, e sem ele a busca varreria a tabela inteira — que é para ter milhões de linhas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        DB::statement('create index leaked_credentials_site_host on leaked_credentials (site_host)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists leaked_credentials_site_host');
    }
};
