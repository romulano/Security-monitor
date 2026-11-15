<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plano empresarial, E5a0 (DESENHO-5.4 §2.2): o canal de aviso pode ser da empresa (`notification_channels`).
 *
 * - `company_id` nulo = canal da conta pessoal (como hoje). Com empresa: `sector_id` nulo = canal **geral** (recebe o
 *   que não tem setor), com setor = canal **do setor** (R11). Apagar a empresa com canais é barrado (restrict: a E6b
 *   apaga na ordem dela); apagar o setor faz o canal virar geral (nullOnDelete, R11; a E2f faz isso antes, com a contagem).
 * - `user_id` continua obrigatório e passa a significar quem conectou; nunca autoriza nada no canal da empresa, e a poda
 *   (P7) passa a linha para a admin.
 * - O check recusa setor sem empresa; o trigger repete as travas da E2b0 (a empresa do canal é a de quem conectou, não
 *   muda de empresa nem volta a pessoal, o setor é da mesma empresa) e mais uma: **canal da empresa nunca é conversa
 *   privada** (Telegram `private` ou com o ID positivo de uma pessoa, Discord `dm`; P1 do 5.4), porque "não existe
 *   notificação por conta" (R11) e o código de segurança só sai por conversa privada. Função própria: o `down` de uma
 *   migration não derruba o trigger da outra.
 * - O único "um app por conta" (`notification_channels_one_per_app`) passa a valer só para a conta pessoal: a empresa pode
 *   ter vários canais do mesmo app no mesmo escopo (P4), e o teto é a contagem (D1, na E5a1).
 * - `sectors.copy_to_general`: o setor manda também para os canais gerais (D5: desligado por padrão; só a admin liga).
 *
 * Compatível com o código no ar: colunas nulas que ninguém lê nem grava, e o código atual nunca põe `company_id`, então o
 * único parcial vale para todas as linhas como o de hoje. Sem backfill: nenhum canal de empresa existe. O `down` falha
 * (inteiro, na transação) se houver canal de empresa: sem a coluna, ele viraria canal pessoal de quem conectou.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        if (! Schema::hasColumn('notification_channels', 'company_id')) {
            Schema::table('notification_channels', function (Blueprint $t) {
                $t->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
                $t->foreignId('sector_id')->nullable()->constrained()->nullOnDelete();
                // Também é o índice da FK em company_id (a coluna da esquerda).
                $t->index(['company_id', 'sector_id']);
                $t->index('sector_id');
            });

            DB::statement('alter table notification_channels add constraint notification_channels_sector_needs_company check (sector_id is null or company_id is not null)');
        }

        if (! Schema::hasColumn('sectors', 'copy_to_general')) {
            Schema::table('sectors', function (Blueprint $t) {
                $t->boolean('copy_to_general')->default(false);
            });
        }

        DB::unprepared(<<<'SQL'
            create or replace function notification_channel_sector_same_company() returns trigger language plpgsql as $$
            begin
                if tg_op = 'UPDATE' and old.company_id is not null and new.company_id is distinct from old.company_id then
                    raise exception 'notification_channels: o canal da empresa não muda de empresa';
                end if;
                if new.company_id is not null and new.company_id is distinct from (
                    select cu.company_id from company_user cu where cu.user_id = new.user_id
                ) then
                    raise exception 'notification_channels: a empresa do canal não é a da conta que o conectou';
                end if;
                if new.sector_id is not null and not exists (
                    select 1 from sectors s where s.id = new.sector_id and s.company_id = new.company_id
                ) then
                    raise exception 'notification_channels: o setor não é da empresa do canal';
                end if;
                -- R11 / P1 do 5.4: o canal da empresa nunca é a conversa de uma pessoa.
                if new.company_id is not null and ((new.type = 'telegram' and new.method = 'private') or (new.type = 'discord' and new.method = 'dm')) then
                    raise exception 'notification_channels: canal da empresa não é conversa privada';
                end if;
                -- E o destino de verdade: o Telegram manda para o telegram_chat_id seja qual for o method, e a conversa
                -- privada é o ID da pessoa (positivo); grupo, supergrupo e canal do Telegram são negativos.
                if new.company_id is not null and new.type = 'telegram' and new.telegram_chat_id is not null and left(new.telegram_chat_id, 1) <> '-' then
                    raise exception 'notification_channels: canal da empresa não é conversa privada';
                end if;
                return new;
            end
            $$;
        SQL);

        DB::unprepared('drop trigger if exists notification_channels_sector_same_company on notification_channels');
        DB::unprepared('create trigger notification_channels_sector_same_company before insert or update of company_id, sector_id, user_id, type, method, telegram_chat_id on notification_channels
            for each row execute function notification_channel_sector_same_company()');

        if (! $this->indexHas('notification_channels_one_per_app', 'company_id')) {
            DB::statement('drop index if exists notification_channels_one_per_app');
            DB::statement("create unique index notification_channels_one_per_app on notification_channels (user_id, type) where type <> 'webhook' and company_id is null");
        }
    }

    public function down(): void
    {
        // Sem a coluna, cada canal da empresa viraria, calado, canal pessoal de quem conectou (e o único antigo poderia
        // quebrar). Com canal de empresa, o down falha inteiro.
        if (Schema::hasColumn('notification_channels', 'company_id')) {
            DB::unprepared("do $$ begin if exists (select 1 from notification_channels where company_id is not null) then
                raise exception 'notification_channels: há canal de empresa; o down o tornaria pessoal'; end if; end $$");
        }

        if ($this->indexHas('notification_channels_one_per_app', 'company_id')) {
            DB::statement('drop index notification_channels_one_per_app');
            DB::statement("create unique index notification_channels_one_per_app on notification_channels (user_id, type) where type <> 'webhook'");
        }

        DB::unprepared('drop trigger if exists notification_channels_sector_same_company on notification_channels');
        DB::unprepared('drop function if exists notification_channel_sector_same_company()');

        if (Schema::hasColumn('sectors', 'copy_to_general')) {
            Schema::table('sectors', fn (Blueprint $t) => $t->dropColumn('copy_to_general'));
        }

        if (Schema::hasColumn('notification_channels', 'company_id')) {
            DB::statement('alter table notification_channels drop constraint if exists notification_channels_sector_needs_company');

            Schema::table('notification_channels', function (Blueprint $t) {
                $t->dropIndex(['company_id', 'sector_id']);
                $t->dropIndex(['sector_id']);
                $t->dropConstrainedForeignId('sector_id');
                $t->dropConstrainedForeignId('company_id');
            });
        }
    }

    /** O índice existe e a definição dele cita a coluna (o único parcial novo cita `company_id`; o antigo, não). */
    private function indexHas(string $name, string $column): bool
    {
        $definition = DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))->where('indexname', $name)->value('indexdef');

        return $definition !== null && str_contains((string) $definition, $column);
    }
};
