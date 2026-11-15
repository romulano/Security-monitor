<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Radar de domínios separado do alerta de notícias: ele passa a ter entrevista
 * própria (domínio, marca, posse), e o domínio do Radar não pertence a alerta.
 *
 * - `monitored_domains.user_id`: o dono do domínio (nos de notícias, o dono do
 *   alerta); `profile_id` passa a aceitar nulo, que é o domínio de um Radar;
 * - `lookalike_watches`: o dono, a empresa e as marcas (antes lidos do alerta)
 *   e o andamento da entrevista (`step_reached`, `completed_at`);
 * - cada Radar que já existe ganha a sua cópia do domínio do alerta, com a mesma
 *   prova de posse e o mesmo aceite: excluir o alerta não leva o Radar junto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_domains', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE monitored_domains ALTER COLUMN profile_id DROP NOT NULL');
        DB::statement('UPDATE monitored_domains d SET user_id = p.user_id FROM alert_profiles p WHERE p.id = d.profile_id');

        Schema::table('lookalike_watches', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->jsonb('brands')->default('[]');
            $table->smallInteger('step_reached')->default(1);
            $table->timestampTz('completed_at')->nullable();
        });

        $watches = DB::table('lookalike_watches as w')
            ->join('monitored_domains as d', 'd.id', '=', 'w.domain_id')
            ->join('alert_profiles as p', 'p.id', '=', 'd.profile_id')
            ->select([
                'w.id as watch_id', 'w.created_at as watch_created_at',
                'd.domain', 'd.verify_token', 'd.verified_at', 'd.verify_checked_at', 'd.consent_at', 'd.consent_ip', 'd.consent_version',
                'p.user_id', 'p.company_name', 'p.name as profile_name', 'p.brands',
            ])
            ->get();

        foreach ($watches as $row) {
            $domainId = DB::table('monitored_domains')->insertGetId([
                'user_id' => $row->user_id,
                'profile_id' => null,
                'domain' => $row->domain,
                'verify_token' => $row->verify_token,
                'verified_at' => $row->verified_at,
                'verify_checked_at' => $row->verify_checked_at,
                'consent_at' => $row->consent_at,
                'consent_ip' => $row->consent_ip,
                'consent_version' => $row->consent_version,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('lookalike_watches')->where('id', $row->watch_id)->update([
                'domain_id' => $domainId,
                'user_id' => $row->user_id,
                'company_name' => $row->company_name ?: $row->profile_name,
                'brands' => $row->brands ?? '[]',
                'step_reached' => 3,
                'completed_at' => $row->watch_created_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        // Cada Radar volta para o domínio de um alerta do mesmo dono com o mesmo nome;
        // os que nasceram na entrevista do Radar, sem alerta, saem (com os achados).
        foreach (DB::table('lookalike_watches as w')->join('monitored_domains as d', 'd.id', '=', 'w.domain_id')->whereNull('d.profile_id')->select('w.id', 'd.user_id', 'd.domain')->get() as $row) {
            $target = DB::table('monitored_domains as d')
                ->whereNotNull('d.profile_id')
                ->where('d.user_id', $row->user_id)
                ->where('d.domain', $row->domain)
                ->whereNotExists(fn ($q) => $q->from('lookalike_watches')->whereColumn('lookalike_watches.domain_id', 'd.id'))
                ->value('d.id');

            if ($target) {
                DB::table('lookalike_watches')->where('id', $row->id)->update(['domain_id' => $target]);
            }
        }

        DB::table('monitored_domains')->whereNull('profile_id')->delete();

        Schema::table('lookalike_watches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['company_name', 'brands', 'step_reached', 'completed_at']);
        });

        DB::statement('ALTER TABLE monitored_domains ALTER COLUMN profile_id SET NOT NULL');

        Schema::table('monitored_domains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
