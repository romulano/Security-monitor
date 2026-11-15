<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 280 caracteres não cabiam uma explicação técnica de verdade: o motivo vai a 600. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_matches', function (Blueprint $table) {
            $table->string('feedback_note', 600)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('alert_matches', function (Blueprint $table) {
            $table->string('feedback_note', 280)->nullable()->change();
        });
    }
};
