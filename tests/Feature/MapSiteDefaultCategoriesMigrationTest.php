<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sites saved with category words that aren't Chaturbate tags showed an
 * empty homepage; the data migration moves each to its nearest real tag.
 */
class MapSiteDefaultCategoriesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_off_list_categories_are_mapped_to_chaturbate_tags_or_cleared(): void
    {
        $alias = Site::factory()->create(['default_category' => 'ass']);
        $noEquivalent = Site::factory()->create(['default_category' => 'general']);
        $alreadyValid = Site::factory()->create(['default_category' => 'feet']);
        $unset = Site::factory()->create(['default_category' => null]);
        $featured = Site::factory()->create(['featured_categories' => ['feet', 'tits', 'dating']]);
        $featuredAllInvalid = Site::factory()->create(['featured_categories' => ['dating', 'video']]);

        $this->runMigration();

        $this->assertSame('bigass', $alias->refresh()->default_category);
        $this->assertNull($noEquivalent->refresh()->default_category);
        $this->assertSame('feet', $alreadyValid->refresh()->default_category);
        $this->assertNull($unset->refresh()->default_category);
        $this->assertSame(['feet', 'bigboobs'], $featured->refresh()->featured_categories);
        $this->assertNull($featuredAllInvalid->refresh()->featured_categories);
    }

    public function test_a_mapped_site_resolves_with_its_new_category_straight_away(): void
    {
        $site = Site::factory()->create(['domains' => ['mapped.test'], 'default_category' => 'amateur']);
        Site::registry();

        $this->runMigration();

        $this->assertSame('new', Site::resolveForHost('mapped.test')->default_category);
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_10_15_221253_map_site_default_categories_to_chaturbate_tags.php');

        $migration->up();
    }
}
