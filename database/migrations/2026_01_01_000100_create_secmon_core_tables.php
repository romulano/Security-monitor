<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chave/valor usado pelo admin para ligar e desligar bots e trocar o
        // driver de IA sem recriar containers.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->jsonb('value')->nullable();
            $table->timestamps();
        });

        // Fontes de crawler (feeds RSS/Atom).
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('host')->index();
            $table->string('feed_url')->unique();
            $table->string('site_url')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedSmallInteger('interval_minutes')->default(60);
            $table->unsignedSmallInteger('max_items')->nullable();
            $table->unsignedTinyInteger('weight')->default(5);
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('articles_count')->default(0);
            $table->timestamps();
        });

        // Casos = a "matéria" agregada.
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('slug')->index();
            $table->string('criticity')->nullable()->index();
            $table->text('summary')->nullable();
            $table->jsonb('indicators')->default('[]');
            $table->jsonb('victim_sectors')->default('[]');
            $table->jsonb('threat_actors')->default('[]');
            $table->jsonb('cves')->default('[]');

            $table->timestampTz('first_at')->nullable()->index();
            $table->timestampTz('latest_at')->nullable()->index();
            $table->timestampTz('first_published_at')->nullable();
            $table->timestampTz('last_published_at')->nullable();

            $table->string('latest_source_host')->nullable()->index();
            $table->text('latest_source_url')->nullable();
            $table->unsignedInteger('source_count')->default(0);
            $table->unsignedInteger('update_count')->default(0);
            $table->unsignedInteger('no_update_count')->default(0);

            // Assinatura usada na deduplicação: tokens normalizados do título.
            $table->text('fingerprint')->nullable();
            $table->boolean('is_imported')->default(false)->index();
            $table->boolean('is_published')->default(true)->index();
            $table->timestampTz('indexed_at')->nullable();
            $table->timestamps();
        });

        // Artigos = cada documento coletado de uma fonte, ligado a um caso.
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('cases')->cascadeOnDelete();

            $table->text('url');
            $table->string('url_hash', 64)->unique();
            $table->string('content_hash', 64)->nullable()->index();
            $table->string('guid')->nullable();

            $table->string('source_host')->nullable()->index();
            $table->text('feed_url')->nullable();

            $table->text('original_title')->nullable();
            $table->text('title')->nullable();
            $table->string('author')->nullable();
            $table->timestampTz('published_at')->nullable()->index();
            $table->timestampTz('collected_at')->nullable()->index();

            $table->longText('content')->nullable();
            $table->text('summary')->nullable();
            $table->text('summary_with_context')->nullable();
            $table->text('new_context')->nullable();
            $table->string('criticity')->nullable()->index();
            $table->jsonb('indicators')->default('[]');

            // pending | enriched | duplicate | no_update | failed | skipped
            $table->string('status')->default('pending')->index();
            $table->boolean('is_first')->default(false);
            $table->boolean('is_new_info')->default(true);
            $table->string('enricher_driver')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['case_id', 'collected_at']);
        });

        // Histórico de execução dos bots.
        Schema::create('crawl_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->string('trigger')->default('schedule'); // schedule | manual | cli
            $table->string('status')->default('running');   // running | ok | failed
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('items_found')->default(0);
            $table->unsignedInteger('items_new')->default(0);
            $table->unsignedInteger('items_duplicate')->default(0);
            $table->unsignedInteger('items_merged')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_runs');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('cases');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('settings');
    }
};
