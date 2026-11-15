<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plano empresarial, o convite (E1c): a conta de empresa só nasce por convite da admin (R12). O token vai só no
 * e-mail; aqui fica o hash dele. Vale 7 dias (D4); reenviar troca o token e o prazo. Um convite aberto por
 * e-mail em cada empresa (índice parcial, sem diferença de caixa). Só tabela nova: o código no ar não a vê.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('email', 255);
            // O papel com que a conta entra; admin não se convida (é uma por empresa).
            $table->string('role', 16);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'accepted_at']);
        });

        DB::statement("alter table company_invites add constraint company_invites_role_check check (role in ('sector_manager', 'member'))");
        DB::statement('create unique index company_invites_one_open on company_invites (company_id, lower(email)) where accepted_at is null and revoked_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invites');
    }
};
