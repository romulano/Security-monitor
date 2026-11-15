<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Admin → Campanhas saiu: a tabela das visitas de anúncio e o gasto copiado de cada plataforma vão embora com ela. */
class DropCampaignVisitsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_12_05_000900_drop_campaign_visits_table.php';

    public function test_the_campaign_visits_and_the_spend_are_gone(): void
    {
        $this->assertFalse(Schema::hasTable('campaign_visits'));

        Settings::setMany(['ads.spend' => ['google' => ['lancamento' => 50]], 'monitor.enabled' => true]);
        $migration = require database_path(self::MIGRATION);
        $migration->up();

        $this->assertFalse(DB::table('settings')->where('key', 'ads.spend')->exists());
        $this->assertTrue(DB::table('settings')->where('key', 'monitor.enabled')->exists());
    }

    public function test_down_brings_the_empty_table_back_and_up_drops_it_again(): void
    {
        $migration = require database_path(self::MIGRATION);

        $migration->down();
        $this->assertTrue(Schema::hasColumns('campaign_visits', ['campaign', 'channel', 'user_id', 'activated_by', 'retained_at']));
        $this->assertSame(0, DB::table('campaign_visits')->count());

        $migration->up();
        $this->assertFalse(Schema::hasTable('campaign_visits'));
    }
}
