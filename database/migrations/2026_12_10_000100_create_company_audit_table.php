<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plano empresarial, a auditoria (§4.9): tudo o que muda a empresa fica registrado, só por acréscimo.
 *
 * - Quem fez (ator) e sobre quem (alvo) ficam sem FK para `users`: o registro sobrevive à poda das contas. O nome
 *   e o e-mail são dado pessoal e, quando a conta é apagada (a poda dos 30 dias; depois a exclusão da empresa, E6b),
 *   viram "conta removida" e fica só o id, no padrão da retenção da P14.
 * - O trigger recusa DELETE e todo UPDATE, menos essa anonimização: `actor_name` e `target_label` só podem virar
 *   "conta removida", e `actor_email` só pode virar nulo. Em `data` não vai dado pessoal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // company (alguém da empresa) | secmon (o admin do Security Monitor) | system (a poda, por exemplo).
            $table->string('actor_kind', 8);
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_name', 255)->nullable();
            $table->string('actor_email', 255)->nullable();
            $table->string('action', 40);
            $table->unsignedBigInteger('target_user_id')->nullable()->index();
            $table->string('target_label', 255)->nullable();
            $table->jsonb('data')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            create function company_audit_append_only() returns trigger language plpgsql as $$
            begin
                -- O TRUNCATE não passa pelo trigger de linha: o de comando, abaixo, cai aqui também.
                if tg_op in ('DELETE', 'TRUNCATE') then
                    raise exception 'company_audit é só de acréscimo';
                end if;
                if (new.id, new.company_id, new.actor_kind, new.actor_id, new.action, new.target_user_id, new.data, new.created_at)
                    is distinct from (old.id, old.company_id, old.actor_kind, old.actor_id, old.action, old.target_user_id, old.data, old.created_at)
                   or (new.actor_name is distinct from old.actor_name and new.actor_name is distinct from 'conta removida')
                   or (new.actor_email is distinct from old.actor_email and new.actor_email is not null)
                   or (new.target_label is distinct from old.target_label and new.target_label is distinct from 'conta removida') then
                    raise exception 'company_audit é só de acréscimo (só a anonimização é permitida)';
                end if;
                return new;
            end
            $$;
            create trigger company_audit_append_only before update or delete on company_audit
                for each row execute function company_audit_append_only();
            create trigger company_audit_no_truncate before truncate on company_audit
                for each statement execute function company_audit_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_audit');
        DB::unprepared('drop function if exists company_audit_append_only()');
    }
};
