<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plano empresarial, os setores (E2a, §4.1): só tabelas novas; o código anterior no ar não as vê.
 *
 * - `sectors`: o nome é único na empresa sem diferença de caixa (índice em lower(name)); o teto (D2, `limit_sectors`)
 *   é conferido pelo serviço com a empresa travada. Apagar a empresa com setores é barrado (restrict), como as
 *   contas: a exclusão da empresa (E6b) apaga cada coisa na ordem dela.
 * - `sector_user`: as contas de cada setor (N:N, R3) e quem responde pelo setor (`is_manager`, §4.3). A admin não
 *   tem linha aqui: responde por todos os setores sem ser marcada (D3). A linha some junto com o setor (E2f) e com a
 *   conta (a poda dos 30 dias, D9). O trigger recusa pôr num setor uma conta que não é da mesma empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->timestampsTz();
        });

        // Também é o índice da FK em company_id (a coluna da esquerda).
        DB::statement('create unique index sectors_company_name_lower_unique on sectors (company_id, lower(name))');

        Schema::create('sector_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sector_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->boolean('is_manager')->default(false);
            $table->timestampsTz();

            // Uma linha por conta e setor; também é o índice da FK em sector_id.
            $table->unique(['sector_id', 'user_id']);
        });

        DB::unprepared(<<<'SQL'
            create function sector_user_same_company() returns trigger language plpgsql as $$
            begin
                if not exists (
                    select 1 from sectors s join company_user cu on cu.company_id = s.company_id
                    where s.id = new.sector_id and cu.user_id = new.user_id
                ) then
                    raise exception 'sector_user: a conta não é da empresa do setor';
                end if;
                return new;
            end
            $$;
            create trigger sector_user_same_company before insert or update of sector_id, user_id on sector_user
                for each row execute function sector_user_same_company();

            -- Os outros lados do trigger (L1/L2 do Red Team): o setor nunca muda de empresa, e a conta que está num
            -- setor não muda de empresa por baixo. A FK de sector_user em company_user(user_id) faz o resto: sem a
            -- linha da empresa (apagada fora do modelo), a conta sai dos setores; trocar o user_id da linha é recusado.
            create function sectors_company_fixed() returns trigger language plpgsql as $$
            begin
                if new.company_id is distinct from old.company_id then
                    raise exception 'sectors: o setor não muda de empresa';
                end if;
                return new;
            end
            $$;
            create trigger sectors_company_fixed before update of company_id on sectors
                for each row execute function sectors_company_fixed();

            create function company_user_keeps_sectors() returns trigger language plpgsql as $$
            begin
                if new.company_id is distinct from old.company_id and exists (select 1 from sector_user where user_id = old.user_id) then
                    raise exception 'company_user: a conta está em setores da empresa';
                end if;
                return new;
            end
            $$;
            create trigger company_user_keeps_sectors before update of company_id on company_user
                for each row execute function company_user_keeps_sectors();

            alter table sector_user add constraint sector_user_company_member_foreign
                foreign key (user_id) references company_user (user_id) on delete cascade;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists company_user_keeps_sectors on company_user; drop function if exists company_user_keeps_sectors()');
        Schema::dropIfExists('sector_user');
        DB::unprepared('drop function if exists sector_user_same_company()');
        DB::unprepared('drop trigger if exists sectors_company_fixed on sectors; drop function if exists sectors_company_fixed()');
        Schema::dropIfExists('sectors');
    }
};
