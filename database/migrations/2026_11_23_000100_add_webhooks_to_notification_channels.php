<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook como quarto notificador (type = webhook): o Security Monitor chama a URL que o
 * cliente cadastrou, num domínio comprovado, com a notificação em JSON. Ao
 * contrário dos apps, a conta pode ter mais de um, cada um com o próprio nome:
 * o único (user_id, type) passa a valer só para Slack, Discord e Telegram.
 *
 * - `webhook_url` (a coluna do Slack e do Discord, criptografada) guarda a URL;
 *   `webhook_host`, o host dela, em claro, para a tela e a posse do domínio;
 * - `webhook_secret` (criptografado no model) assina e autentica cada envio, e
 *   `webhook_secret_at` diz quando foi criado (trocar invalida o anterior);
 * - `domain_id` é a cópia da prova de posse do domínio (monitored_domains), própria
 *   do webhook, como a da stack de testes;
 * - `failures` conta as entregas seguidas que falharam: na 5ª o envio para.
 *
 * Em notification_deliveries, `duration_ms` é quanto a entrega levou (todos os apps).
 *
 * Só acrescenta: colunas que aceitam nulo ou têm padrão, e o índice único vira um
 * parcial com as mesmas linhas de hoje. O código anterior no ar durante a troca
 * continua funcionando.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::table('notification_channels', function (Blueprint $table) {
            $table->string('name', 60)->nullable()->after('type');
            $table->string('webhook_host', 253)->nullable()->after('webhook_url');
            $table->text('webhook_secret')->nullable()->after('webhook_host');
            $table->timestampTz('webhook_secret_at')->nullable()->after('webhook_secret');
            $table->foreignId('domain_id')->nullable()->after('webhook_secret_at')->constrained('monitored_domains')->nullOnDelete();
            $table->unsignedSmallInteger('failures')->default(0)->after('broken_at');
            $table->index('domain_id');
            // O FK de user_id usava o único (user_id, type); o parcial não cobre os webhooks.
            $table->index('user_id');
        });

        DB::statement("CREATE UNIQUE INDEX notification_channels_one_per_app ON notification_channels (user_id, type) WHERE type <> 'webhook'");

        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'type']);
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->unsignedInteger('duration_ms')->nullable()->after('response_code');
        });
    }

    public function down(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropColumn('duration_ms');
        });

        // O único antigo não aceita dois webhooks na mesma conta: eles saem antes.
        DB::table('notification_channels')->where('type', 'webhook')->delete();

        Schema::table('notification_channels', function (Blueprint $table) {
            $table->unique(['user_id', 'type']);
        });

        DB::statement('DROP INDEX IF EXISTS notification_channels_one_per_app');

        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['domain_id']);
            $table->dropConstrainedForeignId('domain_id');
            $table->dropColumn(['name', 'webhook_host', 'webhook_secret', 'webhook_secret_at', 'failures']);
        });
    }
};
