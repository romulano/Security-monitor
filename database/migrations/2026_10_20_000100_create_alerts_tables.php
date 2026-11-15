<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dicionário editável no admin: o que cada opção da entrevista significa
        // na busca (`terms`) e como reconhecê-la num site (`detect`).
        Schema::create('tech_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('category')->index();
            $table->foreignId('parent_id')->nullable()->constrained('tech_catalog')->nullOnDelete();
            $table->jsonb('terms')->default('[]');
            $table->jsonb('detect')->default('[]');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('interview_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('step')->index();
            $table->string('key')->unique();
            $table->string('prompt');
            $table->text('help')->nullable();
            // text | tags | single | multi | catalog
            $table->string('kind');
            $table->string('catalog_category')->nullable();
            $table->jsonb('options')->default('[]');
            // Catálogo filtrado pelas tecnologias escolhidas em outra pergunta
            // (frameworks das linguagens marcadas).
            $table->string('filter_by')->nullable();
            $table->jsonb('show_if')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('active')->default(true);
            // Perguntas cujas respostas alimentam colunas do perfil: não podem
            // ser apagadas nem mudar de tipo.
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('alert_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft')->index(); // draft | active | paused
            $table->unsignedTinyInteger('step_reached')->default(1);
            $table->string('company_name')->nullable();
            $table->jsonb('brands')->default('[]');
            $table->jsonb('sectors')->default('[]');
            $table->jsonb('regions')->default('[]');
            $table->string('server_os')->nullable();
            $table->string('min_criticity')->default('high');
            $table->jsonb('attack_types')->default('[]');
            $table->boolean('include_brand_mentions')->default(true);
            $table->boolean('include_sector_news')->default(false);
            $table->boolean('notify_case_updates')->default(true);
            $table->string('language', 2)->default('pt');
            // match_query = o que vai para o percolator (sem nível);
            // compiled_query = o mesmo com nível, para prévia e "testar no feed".
            $table->text('match_query')->nullable();
            $table->text('compiled_query')->nullable();
            $table->unsignedInteger('query_version')->default(0);
            $table->timestampTz('percolated_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_profile_answers', function (Blueprint $table) {
            $table->foreignId('profile_id')->constrained('alert_profiles')->cascadeOnDelete();
            $table->string('question_key');
            $table->jsonb('value')->nullable();
            $table->timestamps();

            $table->primary(['profile_id', 'question_key']);
        });

        Schema::create('alert_profile_techs', function (Blueprint $table) {
            $table->foreignId('profile_id')->constrained('alert_profiles')->cascadeOnDelete();
            $table->foreignId('tech_id')->constrained('tech_catalog')->cascadeOnDelete();
            $table->string('source')->default('interview'); // interview | discovery | admin
            $table->unsignedSmallInteger('confidence')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->primary(['profile_id', 'tech_id']);
        });

        Schema::create('alert_profile_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('alert_profiles')->cascadeOnDelete();
            $table->string('value');
            $table->string('source')->default('interview');
            $table->timestamps();

            $table->unique(['profile_id', 'value']);
        });

        Schema::create('monitored_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('alert_profiles')->cascadeOnDelete();
            $table->string('domain');
            $table->string('verify_token', 64);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('verify_checked_at')->nullable();
            $table->timestampTz('consent_at')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->string('consent_version', 16)->nullable();
            // pending | running | done | partial | failed | blocked
            $table->string('discovery_status')->default('pending')->index();
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('next_run_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->jsonb('last_log')->default('[]');
            $table->jsonb('hints')->default('[]');
            $table->timestamps();

            $table->unique(['profile_id', 'domain']);
        });

        Schema::create('domain_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('monitored_domains')->cascadeOnDelete();
            $table->foreignId('tech_id')->nullable()->constrained('tech_catalog')->nullOnDelete();
            $table->string('label');
            $table->string('category')->nullable();
            $table->string('version')->nullable();
            $table->text('evidence');
            $table->unsignedSmallInteger('confidence')->default(50);
            $table->string('status')->default('suggested'); // suggested | accepted | ignored
            $table->timestampTz('found_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['domain_id', 'label']);
        });

        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('slack');
            $table->string('method')->default('webhook'); // webhook | oauth
            // Segredos: cast `encrypted` no model (APP_KEY).
            $table->text('webhook_url')->nullable();
            $table->text('bot_token')->nullable();
            $table->string('slack_team')->nullable();
            $table->string('slack_channel')->nullable();
            $table->string('status')->default('active')->index(); // active | broken
            $table->boolean('realtime')->default(true);
            $table->string('quiet_start', 5)->nullable();
            $table->string('quiet_end', 5)->nullable();
            $table->boolean('digest')->default(true);
            $table->string('digest_time', 5)->default('07:00');
            $table->string('digest_scope')->default('all'); // all | profile
            $table->jsonb('digest_days')->default('[1,2,3,4,5]');
            $table->string('timezone')->default('America/Sao_Paulo');
            $table->timestampTz('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('broken_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });

        Schema::create('alert_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('alert_profiles')->cascadeOnDelete();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->string('kind'); // new_case | case_update
            $table->string('criticity')->nullable();
            $table->unsignedSmallInteger('score')->default(0);
            $table->jsonb('reasons')->default('[]');
            // Filtros do perfil: below_level | type_filter | low_score. Nulo = é um alerta.
            $table->string('suppressed_reason')->nullable()->index();
            // pending | sent | held | skipped | failed
            $table->string('delivery_status')->default('pending')->index();
            // held: quiet_hours | throttled · skipped: no_channel | realtime_off | paused | update_ignored
            $table->string('delivery_note')->nullable();
            $table->timestampTz('release_at')->nullable();
            $table->timestampTz('matched_at');
            $table->timestampTz('notified_at')->nullable();
            $table->string('feedback')->nullable(); // relevant | irrelevant
            $table->timestampTz('feedback_at')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'case_id', 'article_id']);
            $table->index(['profile_id', 'matched_at']);
            $table->index(['delivery_status', 'release_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('notification_channels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // realtime | grouped | digest | test
            $table->foreignId('match_id')->nullable()->constrained('alert_matches')->nullOnDelete();
            $table->date('digest_date')->nullable();
            $table->string('status')->default('queued')->index(); // queued | sent | failed | skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('error')->nullable();
            $table->string('summary')->nullable();
            $table->jsonb('payload')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();

            $table->index(['channel_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // Um resumo por canal por dia, mesmo com o scheduler rodando de novo.
        DB::statement("CREATE UNIQUE INDEX notification_deliveries_one_digest_per_day
            ON notification_deliveries (channel_id, digest_date) WHERE kind = 'digest'");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('alert_matches');
        Schema::dropIfExists('notification_channels');
        Schema::dropIfExists('domain_findings');
        Schema::dropIfExists('monitored_domains');
        Schema::dropIfExists('alert_profile_terms');
        Schema::dropIfExists('alert_profile_techs');
        Schema::dropIfExists('alert_profile_answers');
        Schema::dropIfExists('alert_profiles');
        Schema::dropIfExists('interview_questions');
        Schema::dropIfExists('tech_catalog');
    }
};
