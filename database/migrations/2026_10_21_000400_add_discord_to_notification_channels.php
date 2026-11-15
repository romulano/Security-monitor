<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discord como segundo canal do cliente (type = discord), ao lado do Slack.
 * method: `channel` (o bot escreve num canal do servidor), `dm` (mensagem
 * direta para quem conectou) ou `webhook` (colado pelo cliente). Os IDs do
 * Discord são snowflakes de até 20 dígitos: guardados como texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->string('discord_guild_id', 32)->nullable()->after('slack_channel');
            $table->string('discord_guild_name', 120)->nullable()->after('discord_guild_id');
            $table->string('discord_channel_id', 32)->nullable()->after('discord_guild_name');
            $table->string('discord_channel_name', 120)->nullable()->after('discord_channel_id');
            $table->string('discord_user_id', 32)->nullable()->after('discord_channel_name');
            $table->string('discord_username', 120)->nullable()->after('discord_user_id');
            $table->index('discord_guild_id');
        });
    }

    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropIndex(['discord_guild_id']);
            $table->dropColumn(['discord_guild_id', 'discord_guild_name', 'discord_channel_id', 'discord_channel_name', 'discord_user_id', 'discord_username']);
        });
    }
};
