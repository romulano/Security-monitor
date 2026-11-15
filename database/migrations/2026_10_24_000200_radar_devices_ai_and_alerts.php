<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Radar de domínios, segunda versão:
 *
 * - prints por aparelho (navegador, Android, iPhone) em dois momentos: ao
 *   acessar e depois de 10 s na página (`lookalike_captures.shots`);
 * - parecer da IA sobre o achado, motivo da avaliação do cliente (vai para a
 *   IA dos próximos) e as denúncias que ele já fez;
 * - 3 páginas do site do cliente nos 3 aparelhos, como referência;
 * - `lookalike_alerts`: cada aviso (novo ou subiu de nível), com o parecer
 *   da IA antes de sair, como os alertas de notícia;
 * - todo domínio já verificado entra no Radar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lookalike_captures', function (Blueprint $table) {
            // {"desktop": {"first": "...jpg", "second": "...jpg"|null, "changed": bool, "similar": {...}|null}, "android": {...}, "iphone": {...}}
            $table->jsonb('shots')->default('{}');
        });

        DB::table('lookalike_captures')->whereNotNull('desktop_path')->orWhereNotNull('mobile_path')->orderBy('id')->each(function ($row) {
            $shots = array_filter([
                'desktop' => $row->desktop_path ? ['first' => $row->desktop_path, 'second' => null, 'changed' => false] : null,
                'android' => $row->mobile_path ? ['first' => $row->mobile_path, 'second' => null, 'changed' => false] : null,
            ]);
            DB::table('lookalike_captures')->where('id', $row->id)->update(['shots' => json_encode($shots)]);
        });

        Schema::table('lookalike_captures', function (Blueprint $table) {
            $table->dropColumn(['desktop_path', 'mobile_path']);
        });

        Schema::table('lookalike_domains', function (Blueprint $table) {
            $table->smallInteger('ai_score')->nullable();
            $table->text('ai_reason')->nullable();
            $table->string('ai_device', 16)->nullable();
            $table->timestampTz('ai_reviewed_at')->nullable();
            $table->unsignedBigInteger('ai_capture_id')->nullable();
            $table->text('verdict_note')->nullable();
            $table->boolean('verdict_note_flagged')->default(false);
            // {"registrar": {"at": "..."}, "hosting": {...}, "certbr": {...}, "google": {...}, ...}
            $table->jsonb('reports')->default('{}');
        });

        Schema::table('lookalike_watches', function (Blueprint $table) {
            // {"pages": [{"url": "...", "title": "...", "shots": {"desktop": "...", "android": "...", "iphone": "..."}}], "error": null}
            $table->jsonb('reference')->default('{}');
            $table->timestampTz('reference_at')->nullable();
            // pending | done | failed | no_consent
            $table->string('reference_status', 16)->nullable();
        });

        Schema::create('lookalike_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lookalike_id')->constrained('lookalike_domains')->cascadeOnDelete();
            $table->foreignId('capture_id')->nullable()->constrained('lookalike_captures')->nullOnDelete();
            $table->string('kind', 16); // new | raised
            $table->string('risk', 16);
            $table->string('previous_risk', 16)->nullable();
            // ai_review | held | queued | sent | skipped | failed
            $table->string('status', 16)->default('ai_review')->index();
            $table->string('note', 32)->nullable();
            $table->smallInteger('ai_score')->nullable();
            $table->text('ai_reason')->nullable();
            $table->string('ai_device', 16)->nullable();
            $table->timestampTz('ai_reviewed_at')->nullable();
            // O print que vai na mensagem: {"device": "desktop", "moment": "second", "path": "..."}
            $table->jsonb('photo')->default('{}');
            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();
        });

        // Regra: domínio com posse comprovada entra no Radar sozinho.
        $now = now();
        $verified = DB::table('monitored_domains')
            ->whereNotNull('verified_at')
            ->whereNotIn('id', DB::table('lookalike_watches')->select('domain_id'))
            ->pluck('id');

        foreach ($verified as $domainId) {
            DB::table('lookalike_watches')->insert([
                'domain_id' => $domainId,
                'status' => 'active',
                'scan_status' => 'pending',
                'next_scan_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lookalike_alerts');

        Schema::table('lookalike_watches', function (Blueprint $table) {
            $table->dropColumn(['reference', 'reference_at', 'reference_status']);
        });

        Schema::table('lookalike_domains', function (Blueprint $table) {
            $table->dropColumn(['ai_score', 'ai_reason', 'ai_device', 'ai_reviewed_at', 'ai_capture_id', 'verdict_note', 'verdict_note_flagged', 'reports']);
        });

        Schema::table('lookalike_captures', function (Blueprint $table) {
            $table->string('desktop_path')->nullable();
            $table->string('mobile_path')->nullable();
            $table->dropColumn('shots');
        });
    }
};
