<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O assunto central de cada post do TabNews (empresa vítima, produto, malware
 * ou grupo, como "Binance"), que a IA devolve junto com o texto. Por alguns
 * dias depois do post, a matéria com esse nome no título é recusada sem
 * chamar a IA, e o mesmo assunto não sai duas vezes.
 *
 * Só acrescenta uma coluna que aceita nulo: o código anterior no ar durante a
 * troca continua funcionando, e os posts antigos ficam sem assunto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::table('tabnews_posts', function (Blueprint $table) {
            $table->string('subject', 120)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::table('tabnews_posts', function (Blueprint $table) {
            $table->dropColumn('subject');
        });
    }
};
