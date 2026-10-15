<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tags stored as typed ("foot fetish cams") were rejected by Chaturbate's API
 * on every sync; the migration rewrites them in the form it accepts.
 */
class NormalizeSiteTagsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stored_tags_are_rewritten_in_chaturbate_form(): void
    {
        $site = Site::factory()->create();
        $untouched = Site::factory()->create(['tags' => ['feet', 'toes']]);

        // Written around the model, the way the old rows were.
        DB::table('sites')->where('id', $site->id)->update(['tags' => json_encode(['Big Cock', 'cam girls', 'bigcock', 'Vietnamese'])]);

        $this->runMigration();

        $this->assertSame(['bigcock', 'camgirls', 'vietnamese'], $site->refresh()->tags);
        $this->assertSame(['feet', 'toes'], $untouched->refresh()->tags);
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_10_15_224544_normalize_site_tags_to_chaturbate_form.php');

        $migration->up();
    }
}
