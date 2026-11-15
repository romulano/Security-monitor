<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando o endereço do feed de uma fonte mudou pela última vez. O corte do
 * CrawlerService (backfill_days) conta a partir daqui, e não da data de
 * cadastro: o feed novo também traz o arquivo dele, e a fonte antiga com
 * feed novo despejaria esse arquivo como se fosse notícia de agora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->timestampTz('feed_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('feed_changed_at');
        });
    }
};
