<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Campanhas saiu (a divulgação se mede em Admin → Acessos → Origens, pelos
 * registros de acesso): some a tabela das visitas de anúncio, que só ela usava, e o
 * gasto copiado de cada plataforma (Settings `ads.spend`), a única linha dela na
 * tabela `settings`, que é de todos. Roda depois que o app sem Campanhas já está no
 * ar, então ninguém mais grava nelas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('campaign_visits');

        DB::table('settings')->where('key', 'ads.spend')->delete();

        // O cache da configuração (5 min) ainda teria o gasto; sem cache de pé, ele expira sozinho.
        rescue(fn () => Settings::flush(), report: false);
    }

    /** A tabela volta vazia, com o esquema de antes (2026_11_06_000100 + 2026_11_20_000100); o gasto não volta. */
    public function down(): void
    {
        Schema::create('campaign_visits', function (Blueprint $table) {
            $table->id();
            $table->string('campaign', 100);
            $table->string('source', 50)->nullable();
            $table->string('medium', 50)->nullable();
            $table->string('term', 150)->nullable();
            $table->string('landing_path', 255);
            $table->unsignedInteger('pages')->default(1);
            $table->timestamp('reached_signup_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('signed_up_at')->nullable();
            $table->timestamps();
            $table->string('channel', 120)->default('(sem canal)');
            $table->string('content', 100)->nullable();
            $table->string('referrer', 100)->nullable();
            $table->jsonb('first_pages')->nullable();
            $table->string('last_page', 100)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('activated_by', 20)->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('retained_at')->nullable();

            $table->index(['campaign', 'created_at']);
            $table->index('created_at');
            $table->index('user_id');
            $table->index(['channel', 'created_at']);
        });
    }
};
