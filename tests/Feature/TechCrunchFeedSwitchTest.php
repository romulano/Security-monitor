<?php

namespace Tests\Feature;

use App\Models\Source;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** O TechCrunch sai do feed geral para o de segurança e volta ao geral (2026-11-01), sem desfazer o que o admin mudou. */
class TechCrunchFeedSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const GENERAL = 'https://techcrunch.com/feed/';

    private const SECURITY = 'https://techcrunch.com/category/security/feed/';

    private function migrate(): void
    {
        (require database_path('migrations/2026_11_21_000200_switch_techcrunch_to_security_feed.php'))->up();
    }

    private function source(string $feed, string $name = 'TechCrunch', array $extra = []): Source
    {
        return Source::create($extra + ['name' => $name, 'host' => 'techcrunch.com', 'feed_url' => $feed, 'weight' => 4]);
    }

    public function test_the_general_feed_becomes_the_security_one_and_the_cut_restarts(): void
    {
        $source = $this->source(self::GENERAL, extra: ['enabled' => false, 'interval_minutes' => 120]);
        $source->forceFill(['created_at' => now()->subMonth()])->save();

        $this->migrate();

        $source->refresh();
        $this->assertSame(self::SECURITY, $source->feed_url);
        $this->assertSame('TechCrunch — Security', $source->name);
        // O corte do crawler conta da troca, não do cadastro de um mês atrás.
        $this->assertTrue($source->feed_changed_at->isToday());
        // O resto fica como o admin deixou: desligada continua desligada.
        $this->assertFalse($source->enabled);
        $this->assertSame(120, $source->interval_minutes);
    }

    public function test_a_name_the_admin_changed_stays(): void
    {
        $source = $this->source(self::GENERAL, 'TC (geral)');

        $this->migrate();

        $this->assertSame(['TC (geral)', self::SECURITY], [$source->refresh()->name, $source->feed_url]);
    }

    public function test_a_feed_the_admin_changed_stays(): void
    {
        $source = $this->source('https://techcrunch.com/category/startups/feed/');

        $this->migrate();

        $this->assertSame('https://techcrunch.com/category/startups/feed/', $source->refresh()->feed_url);
        $this->assertNull($source->feed_changed_at);
    }

    public function test_nothing_changes_when_the_security_feed_is_already_registered(): void
    {
        $general = $this->source(self::GENERAL);
        $security = $this->source(self::SECURITY, 'TechCrunch Security');

        $this->migrate();

        $this->assertSame(self::GENERAL, $general->refresh()->feed_url);
        $this->assertSame(self::SECURITY, $security->refresh()->feed_url);
        $this->assertSame(2, Source::query()->count());
    }

    public function test_the_general_feed_comes_back_and_the_seeder_does_not_duplicate_it(): void
    {
        $this->source(self::GENERAL);
        $this->migrate();
        $this->migrateBack();

        (new SourceSeeder)->run();

        $this->assertSame([self::GENERAL], Source::query()->where('host', 'techcrunch.com')->pluck('feed_url')->all());
        $this->assertSame('TechCrunch', Source::query()->where('feed_url', self::GENERAL)->value('name'));
    }

    public function test_the_way_back_keeps_what_the_admin_changed(): void
    {
        $renamed = $this->source(self::SECURITY, 'TechCrunch (segurança)');
        $this->migrateBack();
        $this->assertSame([self::GENERAL, 'TechCrunch (segurança)'], [$renamed->refresh()->feed_url, $renamed->name]);

        Source::query()->delete();
        $general = $this->source(self::GENERAL);
        $security = $this->source(self::SECURITY, 'TechCrunch Security');
        $this->migrateBack();
        $this->assertSame([self::GENERAL, self::SECURITY], [$general->refresh()->feed_url, $security->refresh()->feed_url]);
    }

    private function migrateBack(): void
    {
        (require database_path('migrations/2026_11_24_000100_switch_techcrunch_back_to_general_feed.php'))->up();
    }

    public function test_the_migration_goes_back(): void
    {
        $source = $this->source(self::GENERAL);
        $migration = require database_path('migrations/2026_11_21_000200_switch_techcrunch_to_security_feed.php');

        $migration->up();
        $migration->down();

        $source->refresh();
        $this->assertSame([self::GENERAL, 'TechCrunch', null], [$source->feed_url, $source->name, $source->feed_changed_at]);
    }
}
