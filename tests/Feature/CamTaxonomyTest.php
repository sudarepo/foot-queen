<?php

namespace Tests\Feature;

use App\Models\Cam;
use App\Models\Site;
use App\Services\HomepageAbTest;
use App\Services\Providers\ChaturbateProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The category list is only useful if every entry is spelled the way the
 * provider spells it — a display name like "big boobs" would sit in the
 * dropdown forever and match nothing.
 */
class CamTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_featured_category_is_shaped_like_a_raw_provider_tag(): void
    {
        foreach (config('cam-taxonomy.featured_categories') as $category) {
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9]+$/',
                (string) $category,
                "Category '{$category}' isn't a raw feed tag; it would never match a room."
            );
        }
    }

    public function test_the_featured_list_has_no_duplicates(): void
    {
        $categories = config('cam-taxonomy.featured_categories');

        $this->assertSame(array_values(array_unique($categories)), array_values($categories));
    }

    public function test_every_label_override_names_a_featured_category(): void
    {
        $featured = config('cam-taxonomy.featured_categories');

        foreach (array_keys(config('cam-taxonomy.category_labels')) as $category) {
            $this->assertContains((string) $category, $featured);
        }
    }

    public function test_every_category_alias_points_at_a_featured_category_or_nothing(): void
    {
        $featured = config('cam-taxonomy.featured_categories');

        foreach (config('cam-taxonomy.category_aliases') as $alias => $category) {
            $this->assertNotContains((string) $alias, $featured, "'{$alias}' is already a real tag; aliasing it would hide it.");

            if ($category !== null) {
                $this->assertContains($category, $featured, "Alias '{$alias}' points at '{$category}', which isn't a featured category.");
            }
        }
    }

    public function test_a_category_is_normalized_to_its_chaturbate_tag(): void
    {
        $this->assertSame('feet', Site::normalizeCategory(' Feet '));
        $this->assertSame('bigass', Site::normalizeCategory('ass'));
        $this->assertSame('new', Site::normalizeCategory('Amateur'));
        $this->assertNull(Site::normalizeCategory('general'));
        $this->assertNull(Site::normalizeCategory('not-a-tag-at-all'));
        $this->assertNull(Site::normalizeCategory(''));
        $this->assertNull(Site::normalizeCategory(null));
    }

    public function test_a_newly_added_category_is_picked_up_from_a_rooms_tags(): void
    {
        config([
            'cam-providers.chaturbate.affiliate_id' => 'Vg4Qi',
            'cam-providers.chaturbate.campaign' => 'default',
        ]);

        Http::fake([
            'chaturbate.com/api/public/affiliates/onlinerooms/*' => Http::response([
                'count' => 1,
                'results' => [[
                    'username' => 'foxfilms',
                    'gender' => 'f',
                    'current_show' => 'public',
                    'tags' => ['feet', 'daddysgirl', 'pawg', 'notacategory'],
                ]],
            ]),
        ]);

        $cams = app(ChaturbateProvider::class)->fetchCams();

        $this->assertEqualsCanonicalizing(['feet', 'daddysgirl', 'pawg'], $cams[0]['categories']);
        $this->assertContains('notacategory', $cams[0]['tags']);
    }

    public function test_category_labels_prefer_the_override_then_fall_back_to_the_slug(): void
    {
        $this->assertSame('Big pussy lips', Site::categoryLabel('bigpussylips'));
        $this->assertSame('BBC', Site::categoryLabel('bbc'));
        $this->assertSame('Squirt', Site::categoryLabel('squirt'));
        $this->assertSame('18', Site::categoryLabel('18'));
    }

    public function test_the_filter_dropdown_offers_the_new_categories_with_readable_labels(): void
    {
        Cam::factory()->create(['is_online' => true, 'categories' => ['feet']]);

        // Pinned to the grid variant so the filter bar is the one on cams.index.
        $response = $this->withCookie(HomepageAbTest::COOKIE_NAME, 'grid')->get('/');

        $response->assertOk();
        $response->assertSee('value="daddysgirl"', false);
        $response->assertSee("Daddy's girl");
        $response->assertSee('Big pussy lips');
        $response->assertDontSee('Bigpussylips');
    }
}
