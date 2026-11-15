<?php

namespace Tests\Feature\Lookalike;

use App\Models\LookalikeAlert;
use App\Models\LookalikeCapture;
use App\Services\Lookalike\CaptureIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** A poda dos prints do Radar: o que sai, o que fica para sempre e o que sai do disco junto. */
class CapturePruneTest extends TestCase
{
    use LookalikeTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTempBrowserDir();
    }

    protected function tearDown(): void
    {
        $this->tearDownBrowserDir();

        parent::tearDown();
    }

    private function capture(array $attributes = [], bool $withFile = false): LookalikeCapture
    {
        $lookalike = $this->lookalike($this->watch(), 'acmepay-'.uniqid().'.com');
        $capture = $lookalike->captures()->create($attributes + [
            'status' => 'done', 'requested_at' => now(), 'captured_at' => now(), 'keep' => false,
        ]);

        if ($withFile) {
            $relative = "lote1/{$capture->id}-desktop-1.jpg";
            File::ensureDirectoryExists(dirname("{$this->browserDir}/shots/{$relative}"));
            file_put_contents("{$this->browserDir}/shots/{$relative}", 'jpg');
            $capture->forceFill(['shots' => ['desktop' => ['first' => $relative]]])->save();
        }

        $capture->forceFill(['created_at' => $attributes['created_at'] ?? now()->subDays(120)])->saveQuietly();

        return $capture;
    }

    public function test_an_old_capture_that_marked_nothing_is_deleted_with_its_files(): void
    {
        $old = $this->capture(withFile: true);
        $file = "{$this->browserDir}/shots/lote1/{$old->id}-desktop-1.jpg";
        $this->assertFileExists($file);

        $this->assertSame(1, app(CaptureIngestor::class)->prune());

        $this->assertNull(LookalikeCapture::query()->find($old->id));
        $this->assertFileDoesNotExist($file);
    }

    public function test_recent_captures_and_the_ones_kept_as_proof_stay(): void
    {
        $recent = $this->capture(['created_at' => now()->subDays(10)]);
        $proof = $this->capture(['keep' => true]);

        $this->assertSame(0, app(CaptureIngestor::class)->prune());

        $this->assertNotNull($recent->fresh());
        $this->assertNotNull($proof->fresh());
    }

    public function test_the_last_capture_of_a_domain_and_the_one_behind_an_alert_stay(): void
    {
        $last = $this->capture();
        $last->lookalike->forceFill(['last_capture_id' => $last->id])->save();

        $alerted = $this->capture();
        LookalikeAlert::query()->create([
            'lookalike_id' => $alerted->lookalike_id,
            'capture_id' => $alerted->id,
            'kind' => 'new',
            'risk' => 'high',
        ]);
        $free = $this->capture();

        $this->assertSame(1, app(CaptureIngestor::class)->prune());

        $this->assertNotNull($last->fresh());
        $this->assertNotNull($alerted->fresh());
        $this->assertNull($free->fresh());
    }

    public function test_the_retention_comes_from_the_config_and_many_captures_are_pruned_in_pages(): void
    {
        config(['secmon.lookalikes.keep_days' => 30]);
        $captures = collect(range(1, 5))->map(fn () => $this->capture(['created_at' => now()->subDays(40)]));
        $recent = $this->capture(['created_at' => now()->subDays(20)]);

        $this->assertSame(5, app(CaptureIngestor::class)->prune());

        $this->assertSame(0, LookalikeCapture::query()->whereIn('id', $captures->pluck('id'))->count());
        $this->assertNotNull($recent->fresh());
    }
}
