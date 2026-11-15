<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Relatório em pt-BR ao lado do inglês. Nulo = só existe a versão em
        // inglês (extração heurística ou análise anterior a esta coluna), e a UI
        // cai para ela.
        Schema::table('cases', function (Blueprint $table) {
            $table->text('title_pt')->nullable()->after('title');
            $table->text('summary_pt')->nullable()->after('summary');
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->text('title_pt')->nullable()->after('title');
            $table->text('summary_pt')->nullable()->after('summary');
            $table->text('summary_with_context_pt')->nullable()->after('summary_with_context');
            $table->text('new_context_pt')->nullable()->after('new_context');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn(['title_pt', 'summary_pt']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['title_pt', 'summary_pt', 'summary_with_context_pt', 'new_context_pt']);
        });
    }
};
