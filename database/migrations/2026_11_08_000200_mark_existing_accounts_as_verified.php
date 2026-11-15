<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O cadastro passou a ser aberto, com o e-mail confirmado por link antes de usar
 * os alertas. As contas de antes entraram por convite (o admin as conhecia) e não
 * recebem o pedido de confirmação: contam como confirmadas desde a criação.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Sem volta: não dá para saber quais confirmações vieram daqui e quais do link.
    }
};
