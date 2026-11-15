<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // full     = corpo da matéria extraído do site
            // summary  = site não entregou o corpo (WAF/JS); usou o resumo do feed
            // empty    = nem corpo nem resumo
            $table->string('extraction')->nullable()->index()->after('status');
            $table->unsignedInteger('content_chars')->default(0)->after('extraction');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['extraction', 'content_chars']);
        });
    }
};
