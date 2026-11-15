<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plano empresarial, E2b0 (DESENHO-5.2 §3): a empresa e o setor nas raízes do que se monitora — o alerta de Notícias
 * (`alert_profiles`), o Radar (`lookalike_watches`) e o GitHub (`github_monitors`). Os filhos (matches, imitações,
 * prints, avisos, achados, domínios) herdam pela FK e não ganham coluna.
 *
 * - `company_id` nulo = conta pessoal (como hoje); `sector_id` nulo = sem setor (a empresa toda vê, R4). Apagar a
 *   empresa com itens é barrado (restrict): a exclusão da empresa (E6b) apaga na ordem dela. Apagar o setor deixa o
 *   item sem setor (nullOnDelete), a rede de segurança da E2f.
 * - O check recusa setor sem empresa, e o trigger recusa o setor de outra empresa (como o `sector_user_same_company`
 *   da E2a): é a trava no banco contra item de uma empresa num setor de outra. O trigger também amarra a empresa do
 *   item à de quem o criou (`user_id`): a conta pessoal nunca tem item de empresa (R12, e o único parcial do GitHub
 *   não fura), o dono não vira conta de outra empresa, e o item de empresa não muda de empresa nem volta a pessoal
 *   (N1 do Red Team). A conta de empresa ainda grava sem `company_id` até a E2c/E2d carimbarem na criação.
 * - `github_monitors`: o único em `user_id` passa a valer só para a conta pessoal (`company_id is null`); a empresa
 *   terá vários (limite na E5d). A FK continua com índice.
 *
 * Compatível com o código no ar: colunas nulas que ninguém lê nem grava, e o código atual nunca põe `company_id`, então
 * o único parcial vale para todos como o de hoje. Sem backfill: nenhum item de empresa existe ainda. O `down` recria o
 * único do GitHub e falha se uma conta tiver dois monitores, o que só acontece depois que a empresa puder ter vários.
 */
return new class extends Migration
{
    private const TABLES = ['alert_profiles', 'lookalike_watches', 'github_monitors'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
                $t->foreignId('sector_id')->nullable()->constrained()->nullOnDelete();
                // Também é o índice da FK em company_id (a coluna da esquerda).
                $t->index(['company_id', 'sector_id']);
                $t->index('sector_id');
            });

            DB::statement("alter table {$table} add constraint {$table}_sector_needs_company check (sector_id is null or company_id is not null)");
        }

        DB::unprepared(<<<'SQL'
            create or replace function monitor_sector_same_company() returns trigger language plpgsql as $$
            begin
                if tg_op = 'UPDATE' and old.company_id is not null and new.company_id is distinct from old.company_id then
                    raise exception '%: o item da empresa não muda de empresa', tg_table_name;
                end if;
                if new.company_id is not null and new.company_id is distinct from (
                    select cu.company_id from company_user cu where cu.user_id = new.user_id
                ) then
                    raise exception '%: a empresa do item não é a da conta que o criou', tg_table_name;
                end if;
                if new.sector_id is not null and not exists (
                    select 1 from sectors s where s.id = new.sector_id and s.company_id = new.company_id
                ) then
                    raise exception '%: o setor não é da empresa do item', tg_table_name;
                end if;
                return new;
            end
            $$;
        SQL);

        foreach (self::TABLES as $table) {
            DB::unprepared("drop trigger if exists {$table}_sector_same_company on {$table}");
            DB::unprepared("create trigger {$table}_sector_same_company before insert or update of company_id, sector_id, user_id on {$table}
                for each row execute function monitor_sector_same_company()");
        }

        if (! $this->hasIndex('github_monitors_one_per_account')) {
            Schema::table('github_monitors', function (Blueprint $t) {
                $t->dropUnique(['user_id']);
                $t->index('user_id');
            });

            DB::statement('create unique index github_monitors_one_per_account on github_monitors (user_id) where company_id is null');
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('github_monitors_one_per_account')) {
            DB::statement('drop index github_monitors_one_per_account');

            Schema::table('github_monitors', function (Blueprint $t) {
                $t->dropIndex(['user_id']);
                $t->unique('user_id');
            });
        }

        foreach (self::TABLES as $table) {
            DB::unprepared("drop trigger if exists {$table}_sector_same_company on {$table}");

            if (! Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            DB::statement("alter table {$table} drop constraint if exists {$table}_sector_needs_company");

            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['company_id', 'sector_id']);
                $t->dropIndex(['sector_id']);
                $t->dropConstrainedForeignId('sector_id');
                $t->dropConstrainedForeignId('company_id');
            });
        }

        DB::unprepared('drop function if exists monitor_sector_same_company()');
    }

    private function hasIndex(string $name): bool
    {
        return DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))->where('indexname', $name)->exists();
    }
};
