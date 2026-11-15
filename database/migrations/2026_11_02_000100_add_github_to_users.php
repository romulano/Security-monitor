<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entrar com o GitHub: o id numérico é o que identifica a pessoa (o login muda,
        // o id não); o login só aparece para o admin saber de quem é a conta.
        Schema::table('users', function (Blueprint $table) {
            $table->string('github_id', 20)->nullable()->unique()->after('email');
            $table->string('github_login', 60)->nullable()->after('github_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['github_id', 'github_login']);
        });
    }
};
