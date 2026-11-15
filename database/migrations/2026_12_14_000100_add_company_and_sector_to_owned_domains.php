<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plano empresarial, E3a0 (DESENHO-5.3 §2.2): a posse do domínio passa a poder ser da empresa (`owned_domains`).
 *
 * - `company_id` nulo = posse da conta pessoal (como hoje); com empresa, a prova é o token da empresa e não depende de
 *   quem comprovou (§4.4). `sector_id` nulo = domínio sem setor (a empresa toda vê, R4); com setor, só o setor e a
 *   admin (R10). Apagar a empresa com domínios é barrado (restrict: a E6b apaga na ordem dela); apagar o setor deixa o
 *   domínio sem setor (nullOnDelete, a rede de segurança da E2f).
 * - `user_id` continua obrigatório e passa a significar quem cadastrou; a poda (P7) passa a linha para a admin.
 * - O check recusa setor sem empresa, e o trigger repete as travas da E2b0 (a empresa do domínio é a de quem cadastrou,
 *   não muda de empresa nem volta a pessoal, o setor é da mesma empresa), e mais uma da posse: a linha da empresa só
 *   leva o token da empresa (sem `tokens` legados; `proof_token` nulo ou o dela), inclusive a pessoal que passa a ela. Função própria, e não a
 *   `monitor_sector_same_company()` da E2b0: o `down` de uma migration não derruba o trigger da outra.
 * - O único `(user_id, domain)` vira dois parciais: um nome por conta pessoal, um nome por empresa. Ele era também o
 *   índice da FK `user_id`, que ganha índice próprio.
 * - `subdomains_checked_at`/`subdomains_error`: o estado da descoberta passiva de subdomínios (E3c, P3 do 5.3), já aqui
 *   para a E3c não precisar de outra migration em tabela existente.
 *
 * Compatível com o código no ar: colunas nulas que ninguém lê nem grava, e o código atual nunca põe `company_id`, então
 * o único parcial da conta vale para todas as linhas como o de hoje. Sem backfill: nenhuma posse de empresa existe. O
 * `down` falha (inteiro, na transação) se houver qualquer posse de empresa, o que só acontece depois da E3a1: sem a
 * coluna, ela viraria posse pessoal de quem cadastrou, com o token da empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        if (! Schema::hasColumn('owned_domains', 'company_id')) {
            Schema::table('owned_domains', function (Blueprint $t) {
                $t->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
                $t->foreignId('sector_id')->nullable()->constrained()->nullOnDelete();
                $t->timestampTz('subdomains_checked_at')->nullable();
                $t->string('subdomains_error', 64)->nullable();
                // Também é o índice da FK em company_id (a coluna da esquerda).
                $t->index(['company_id', 'sector_id']);
                $t->index('sector_id');
            });

            DB::statement('alter table owned_domains add constraint owned_domains_sector_needs_company check (sector_id is null or company_id is not null)');
        }

        DB::unprepared(<<<'SQL'
            create or replace function owned_domain_sector_same_company() returns trigger language plpgsql as $$
            begin
                if tg_op = 'UPDATE' and old.company_id is not null and new.company_id is distinct from old.company_id then
                    raise exception 'owned_domains: o domínio da empresa não muda de empresa';
                end if;
                -- A empresa prova só com o token dela (DESENHO-5.3 §2.3): sem tokens legados, e a prova é a do token dela.
                -- Vale também para a posse pessoal que passa à empresa (o carimbo da E3a2): só sem os tokens da conta.
                if new.company_id is not null and (coalesce(new.tokens, '[]'::jsonb) <> '[]'::jsonb
                    or (new.proof_token is not null and new.proof_token is distinct from (select c.domain_token from companies c where c.id = new.company_id))) then
                    raise exception 'owned_domains: a posse da empresa só leva o token da empresa';
                end if;
                if new.company_id is not null and new.company_id is distinct from (
                    select cu.company_id from company_user cu where cu.user_id = new.user_id
                ) then
                    raise exception 'owned_domains: a empresa do domínio não é a da conta que o cadastrou';
                end if;
                if new.sector_id is not null and not exists (
                    select 1 from sectors s where s.id = new.sector_id and s.company_id = new.company_id
                ) then
                    raise exception 'owned_domains: o setor não é da empresa do domínio';
                end if;
                return new;
            end
            $$;
        SQL);

        DB::unprepared('drop trigger if exists owned_domains_sector_same_company on owned_domains');
        DB::unprepared('create trigger owned_domains_sector_same_company before insert or update of company_id, sector_id, user_id, tokens, proof_token on owned_domains
            for each row execute function owned_domain_sector_same_company()');

        if (! $this->hasIndex('owned_domains_one_per_account')) {
            Schema::table('owned_domains', function (Blueprint $t) {
                $t->index('user_id');
                $t->dropUnique(['user_id', 'domain']);
            });

            DB::statement('create unique index owned_domains_one_per_account on owned_domains (user_id, domain) where company_id is null');
            DB::statement('create unique index owned_domains_one_per_company on owned_domains (company_id, domain) where company_id is not null');
        }
    }

    public function down(): void
    {
        // Sem a coluna, cada posse da empresa viraria, calada, posse pessoal de quem cadastrou, comprovada com o token
        // da empresa (que entraria no conjunto de tokens da conta). Com posse de empresa, o down falha inteiro.
        if (Schema::hasColumn('owned_domains', 'company_id')) {
            DB::unprepared("do $$ begin if exists (select 1 from owned_domains where company_id is not null) then
                raise exception 'owned_domains: há posse de empresa; o down a tornaria pessoal'; end if; end $$");
        }

        if ($this->hasIndex('owned_domains_one_per_account')) {
            DB::statement('drop index if exists owned_domains_one_per_company');
            DB::statement('drop index owned_domains_one_per_account');

            Schema::table('owned_domains', function (Blueprint $t) {
                $t->unique(['user_id', 'domain']);
                $t->dropIndex(['user_id']);
            });
        }

        DB::unprepared('drop trigger if exists owned_domains_sector_same_company on owned_domains');
        DB::unprepared('drop function if exists owned_domain_sector_same_company()');

        if (Schema::hasColumn('owned_domains', 'company_id')) {
            DB::statement('alter table owned_domains drop constraint if exists owned_domains_sector_needs_company');

            Schema::table('owned_domains', function (Blueprint $t) {
                $t->dropIndex(['company_id', 'sector_id']);
                $t->dropIndex(['sector_id']);
                $t->dropConstrainedForeignId('sector_id');
                $t->dropConstrainedForeignId('company_id');
                $t->dropColumn(['subdomains_checked_at', 'subdomains_error']);
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        return DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))->where('indexname', $name)->exists();
    }
};
