<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * As contas do Security Monitor nos canais de divulgação, uma por plataforma (por ora,
 * só o TabNews). `session_token` é o cookie de sessão da conta, criptografado
 * pelo model; `username` é quem a plataforma disse que é o dono do cookie na
 * última conferência (`checked_at`), e `last_error` o motivo de ele ter parado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::create('outreach_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20)->unique();
            $table->string('username', 60)->nullable();
            $table->text('session_token');
            $table->timestampTz('checked_at')->nullable();
            $table->string('last_error', 200)->nullable();
            $table->timestampsTz();
        });

        DB::statement("alter table outreach_accounts add constraint outreach_accounts_platform_check check (platform in ('tabnews'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_accounts');
    }
};
