<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plano empresarial, E3b (DESENHO-5.3 §3.1): os subdomínios de um domínio comprovado da empresa (R6). O subdomínio é
 * filho do domínio: herda a posse, a empresa e o setor dele (sem coluna própria), e some com ele (cascade).
 *
 * - `origin`: `passive` (a descoberta da E3c) ou `manual` (cadastrado à mão), a 1ª vez que apareceu;
 * - `status`: `active` (conta no teto de 20 e vai ao Radar), `suggested`, `ignored` (tirado pela pessoa: a descoberta
 *   não traz de volta) ou `gone` (deixou de resolver);
 * - `reason`: por que é sugestão ou saiu; `facts`: o que o DNS disse (a cadeia de CNAME, a delegação, a zona, até 5 IPs);
 *   `facts.outside`: aponta para fora da zona da empresa (P5: ativo, mas nunca infraestrutura própria nem print).
 *
 * O trigger recusa nome fora do domínio (ou igual a ele), nome fora do formato, subdomínio de domínio que não é de
 * empresa (P4) e a troca de domínio ou de nome por update. Tabela nova:
 * o código no ar não a vê.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owned_subdomains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owned_domain_id')->constrained()->cascadeOnDelete();
            $table->string('name', 253);
            $table->string('origin', 8);
            $table->string('status', 10);
            $table->string('reason', 24)->nullable();
            $table->jsonb('facts')->default('{}');
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // Também é o índice da FK owned_domain_id.
            $table->unique(['owned_domain_id', 'name']);
            $table->index('activated_by');
        });

        DB::statement("alter table owned_subdomains add constraint owned_subdomains_origin check (origin in ('passive', 'manual'))");
        DB::statement("alter table owned_subdomains add constraint owned_subdomains_status check (status in ('active', 'suggested', 'ignored', 'gone'))");
        // O teto de 20 conta os ativos de um domínio.
        DB::statement("create index owned_subdomains_active on owned_subdomains (owned_domain_id) where status = 'active'");

        DB::unprepared(<<<'SQL'
            create or replace function owned_subdomain_inside_domain() returns trigger language plpgsql as $$
            declare
                parent owned_domains%rowtype;
            begin
                -- O subdomínio não muda de domínio nem de nome (Y2 do Red Team da E3b1): tirar e cadastrar de novo.
                if tg_op = 'UPDATE' and (new.owned_domain_id <> old.owned_domain_id or new.name <> old.name) then
                    raise exception 'owned_subdomains: o subdomínio não muda de domínio nem de nome';
                end if;
                -- O nome como o serviço normaliza (DomainName::isValid): minúsculo, rótulos válidos, sem curinga nem "_".
                if length(new.name) > 253 or new.name !~ '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$' then
                    raise exception 'owned_subdomains: nome inválido';
                end if;
                select * into parent from owned_domains where id = new.owned_domain_id;
                if parent.company_id is null then
                    raise exception 'owned_subdomains: subdomínio só de domínio da empresa';
                end if;
                if new.name = parent.domain or right(new.name, length(parent.domain) + 1) <> '.' || parent.domain then
                    raise exception 'owned_subdomains: o nome não é subdomínio do domínio';
                end if;
                return new;
            end
            $$;
        SQL);

        DB::unprepared('create trigger owned_subdomains_inside_domain before insert or update of name, owned_domain_id on owned_subdomains
            for each row execute function owned_subdomain_inside_domain()');
    }

    public function down(): void
    {
        Schema::dropIfExists('owned_subdomains');
        DB::unprepared('drop function if exists owned_subdomain_inside_domain()');
    }
};
