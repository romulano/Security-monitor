<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A verificação em duas etapas (TOTP) de cada conta: uma linha por conta. O segredo vai
        // cifrado com a APP_KEY (cast `encrypted`); sem `confirmed_at`, a configuração começou e
        // ainda não vale. `last_used_step` é o último passo de 30 s aceito (um código vale uma vez);
        // `recovery_codes`, os códigos de recuperação ainda não usados, só o hash de cada um.
        Schema::create('user_two_factor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('secret');
            $table->timestampTz('confirmed_at')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->json('recovery_codes')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_two_factor');
    }
};
