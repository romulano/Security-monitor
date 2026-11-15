<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plano empresarial, o carimbo dos canais (E5b1, DESENHO-5.4 §2.8; o mesmo SQL do CompanyStamp::channels, para o deploy
 * não depender de alguém rodar o `secmon:company-stamp`): o canal que a admin ativa de uma empresa conectou antes da E5a
 * vira canal geral da empresa (P2: geral só a admin); a conversa privada (Telegram privado, DM do Discord; P1) e o canal de
 * membro, de responsável ou de conta desativada saem (Red Team da E5b1).
 *
 * - Idempotente: só toca o que ainda está sem empresa; rodar de novo não muda nada. Conta pessoal fica como está.
 * - Em produção não acha nada (a chave está desligada e nenhuma empresa existe). O trigger da E5a0 confere cada linha.
 * - Junto do código que roteia pelo escopo (E5b1): antes dele, o carimbo tiraria os canais de quem criou e os alertas
 *   ficariam sem destino.
 * - Sem volta: o `down` não tira a empresa (o trigger recusa) nem recria a conversa privada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ofCompany = 'company_id is null and exists (select 1 from company_user cu where cu.user_id = notification_channels.user_id)';
        $activeAdmin = "exists (select 1 from company_user a where a.user_id = notification_channels.user_id and a.role = 'admin' and a.deactivated_at is null)";

        // O SQL fica aqui, e não na constante do CompanyStamp: a migration não muda se o código mudar.
        $private = "(type = 'telegram' and (method = 'private' or (telegram_chat_id is not null and left(telegram_chat_id, 1) <> '-'))) or (type = 'discord' and method = 'dm')";

        DB::delete("delete from notification_channels where {$ofCompany} and (({$private}) or not {$activeAdmin})");
        DB::update("update notification_channels c set company_id = cu.company_id, sector_id = null from company_user cu where cu.user_id = c.user_id and c.company_id is null and cu.role = 'admin' and cu.deactivated_at is null");
    }

    public function down(): void {}
};
