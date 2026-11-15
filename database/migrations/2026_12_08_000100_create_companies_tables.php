<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plano empresarial, a estrutura (E1a): a empresa e as contas dela. Só tabelas novas: o código
 * anterior no ar não as vê, e a conta pessoal continua igual (nenhuma coluna em `users`).
 *
 * - `companies`: o token do TXT da empresa (`secmon-verify=<token>`, §4.4), os limites e as chaves.
 *   Cada limite é uma coluna nula: nulo = o padrão de `secmon.enterprise.limits`, que pode mudar na
 *   config sem migrar nada; só o que o admin do Security Monitor ajustou para a empresa fica gravado.
 *   A empresa só vale com a chave global (ENTERPRISE_ENABLED) e a dela (`enterprise_enabled_at`,
 *   a empresa piloto do D12) ligadas e sem `suspended_at` (D7: dados intactos, acesso bloqueado).
 * - `company_user`: a conta de empresa (R12). Uma conta está em no máximo uma empresa (único em
 *   user_id) e a empresa tem no máximo uma admin (único parcial). A conta removida pela admin
 *   continua ligada à empresa, desativada (`deactivated_at`, §4.6/D9): não vira conta pessoal.
 *   Apagar a empresa com contas é barrado (restrict): sem a linha, a conta pareceria pessoal.
 */
return new class extends Migration
{
    /** Coluna de cada limite (a chave em `secmon.enterprise.limits` com o prefixo `limit_`). */
    private const LIMITS = [
        'accounts', 'domains', 'subdomains', 'sectors', 'github_monitors', 'alert_profiles',
        'sector_channels', 'general_channels', 'webhooks', 'radar_seeds',
    ];

    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // O token do TXT de posse da empresa: o mesmo formato do de conta (users.domain_token).
            $table->string('domain_token', 64)->unique();

            foreach (self::LIMITS as $limit) {
                $table->unsignedInteger('limit_'.$limit)->nullable();
            }

            $table->boolean('require_2fa')->default(false);
            // A chave da empresa (D12): nula = desligada, mesmo com ENTERPRISE_ENABLED.
            $table->timestampTz('enterprise_enabled_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampsTz();
        });

        foreach (self::LIMITS as $limit) {
            DB::statement("alter table companies add constraint companies_limit_{$limit}_check check (limit_{$limit} >= 0)");
        }

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // Único: uma conta pertence a no máximo uma empresa (também é o índice da FK).
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'role']);
        });

        DB::statement("alter table company_user add constraint company_user_role_check check (role in ('admin', 'sector_manager', 'member'))");
        DB::statement("create unique index company_user_one_admin on company_user (company_id) where role = 'admin'");
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
    }
};
