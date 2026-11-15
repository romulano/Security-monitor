<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O gasto das campanhas (Admin → Campanhas, Settings `ads.spend`) era um valor por
 * campanha; agora é por canal e campanha ({canal: {campanha: valor}}), porque o mesmo
 * nome de campanha pode rodar no Google, na Meta e no Reddit. Cada gasto antigo vai
 * para o canal que mais trouxe visita naquela campanha.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stored = DB::table('settings')->where('key', 'ads.spend')->value('value');
        $map = $stored ? (json_decode($stored, true)['v'] ?? null) : null;

        if (! is_array($map) || $map === []) {
            return;
        }

        $nested = [];

        foreach ($map as $campaign => $value) {
            if (is_array($value)) {
                $nested[$campaign] = array_merge($nested[$campaign] ?? [], $value);

                continue;
            }

            $channel = DB::table('campaign_visits')->where('campaign', (string) $campaign)
                ->groupBy('channel')->orderByRaw('count(*) desc')->value('channel') ?? '(sem canal)';
            $nested[$channel][(string) $campaign] = $value;
        }

        DB::table('settings')->where('key', 'ads.spend')->update(['value' => json_encode(['v' => $nested])]);

        // O cache da configuração (5 min) ainda teria o formato antigo; sem cache de pé, ele expira sozinho.
        rescue(fn () => Settings::flush(), report: false);
    }

    public function down(): void
    {
        // Sem volta: o mesmo nome de campanha em dois canais não cabe no formato antigo.
    }
};
