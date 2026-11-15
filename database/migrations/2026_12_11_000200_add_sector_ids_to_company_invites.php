<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plano empresarial, os setores pré-escolhidos no convite (E2a3, §4.1): os ids dos setores em que a conta entra no
 * aceite. Uma lista (jsonb) e não uma tabela: o setor apagado entre o convite e o aceite só é ignorado no aceite, que
 * confere cada id contra os setores da empresa. Coluna nova com padrão: o código anterior no ar não a vê.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_invites', function (Blueprint $table) {
            $table->jsonb('sector_ids')->default('[]');
        });

        // Sempre uma lista (P2 do Red Team): o escalar, o objeto e o null do JSON ficam de fora já no banco.
        DB::statement("alter table company_invites add constraint company_invites_sector_ids_list check (jsonb_typeof(sector_ids) = 'array')");
    }

    public function down(): void
    {
        DB::statement('alter table company_invites drop constraint if exists company_invites_sector_ids_list');

        Schema::table('company_invites', function (Blueprint $table) {
            $table->dropColumn('sector_ids');
        });
    }
};
