<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Os registros de posse que nenhum serviço usa mais (o Radar, o alerta ou o webhook do nome foi apagado)
 * continuavam aparecendo em Chaves. Saem os que não têm chave achada; os com achado ficam para o dono decidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('owned_domains')
            ->whereNotExists(fn ($q) => $q->from('monitored_domains')
                ->whereColumn('monitored_domains.user_id', 'owned_domains.user_id')
                ->whereColumn('monitored_domains.domain', 'owned_domains.domain'))
            ->whereNotExists(fn ($q) => $q->from('exposed_keys')->whereColumn('exposed_keys.owned_domain_id', 'owned_domains.id'))
            ->delete();
    }

    public function down(): void {}
};
