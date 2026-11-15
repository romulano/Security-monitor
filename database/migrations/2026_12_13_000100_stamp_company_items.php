<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plano empresarial, o carimbo retroativo (E2g, o mesmo SQL do CompanyStamp, para o deploy não depender de alguém rodar o
 * `secmon:company-stamp`): o que uma conta de empresa criou com `company_id` nulo ganha a empresa de quem criou, sem setor.
 *
 * - Idempotente: só toca o que ainda está nulo; rodar de novo não muda nada. Conta pessoal fica como está.
 * - Seguro com o código anterior no ar durante o deploy: em produção ainda não há empresa (a chave está desligada), então
 *   não acha nada; e onde houver, o código anterior ou não lê `company_id` (antes da visibilidade) ou passa a mostrar o
 *   item a quem o criou e à empresa (depois). O que o código anterior criar entre esta migration e a troca fica nulo
 *   até o próximo `secmon:company-stamp`.
 * - O trigger da E2b0 confere cada linha (a empresa é a do `company_user` do dono). O único parcial do GitHub não fura:
 *   ele vale só para `company_id` nulo.
 * - Sem volta: o `down` não tira a empresa (o trigger recusa, e o item já pode ter setor).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['alert_profiles', 'lookalike_watches', 'github_monitors'] as $table) {
            DB::update("update {$table} t set company_id = cu.company_id from company_user cu where cu.user_id = t.user_id and t.company_id is null");
        }
    }

    public function down(): void {}
};
