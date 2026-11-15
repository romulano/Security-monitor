<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram como terceiro canal do cliente (type = telegram). method:
 * `private` (conversa privada com o bot) ou `group` (o bot num grupo). O ID
 * do chat de um grupo é negativo e pode passar de 13 dígitos: guardado como texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->string('telegram_chat_id', 32)->nullable()->after('discord_username');
            $table->string('telegram_chat_title', 120)->nullable()->after('telegram_chat_id');
            $table->string('telegram_username', 120)->nullable()->after('telegram_chat_title');
            $table->index('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropIndex(['telegram_chat_id']);
            $table->dropColumn(['telegram_chat_id', 'telegram_chat_title', 'telegram_username']);
        });
    }
};
