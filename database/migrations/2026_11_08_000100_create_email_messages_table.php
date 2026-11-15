<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada e-mail que o Security Monitor envia (Admin → E-mails): para quem, o assunto e até onde
 * chegou. O app entrega ao servidor de envio (docker/mail) e anota o id da fila
 * dele; o log do Postfix diz depois se o servidor do destinatário aceitou, adiou
 * ou devolveu, com a resposta dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 40);
            $table->string('to_address', 255);
            $table->string('subject', 255);
            // na_fila → aceito (pelo servidor de envio) → entregue | adiado | devolvido; falhou = nem chegou ao servidor
            $table->string('status', 20)->default('na_fila');
            $table->string('queue_id', 40)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_response')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('queue_id');
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
