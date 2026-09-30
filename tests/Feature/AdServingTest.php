<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Cam;
use App\Models\Site;
use App\Services\AdPlacement;
use App\Services\HomepageAbTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdServingTest extends TestCase
{
    use RefreshDatabase;

    private function bbwcamsSite(): Site
    {
        return Site::factory()->create([
            'slug' => 'bbw-cams',
            'name' => 'BBW Cams',
            'domains' => ['bbwcams.test'],
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    public function test_the_homepage_renders_the_homepage_banner_for_the_current_site(): void
    {
        $site = $this->bbwcamsSite();

        Ad::create([
            'site_id' => $site->id,
            'placement' => AdPlacement::HomepageHeader->value,
            'advertiser' => 'Sample Advertiser',
            'campaign' => 'Homepage Run',
            'creative' => 'Homepage Banner',
            'destination_url' => 'https://advertiser.example/home',
            'image_path' => 'ads/homepage-banner.jpg',
            'alt_text' => 'Homepage Banner',
            'is_active' => true,
            'weight' => 10,
        ]);

        $response = $this->withCookie(HomepageAbTest::COOKIE_NAME, HomepageAbTest::VARIANT_GRID)
            ->get('http://bbwcams.test/');

        $response->assertOk();
        $response->assertSee('ad-slot--homepage_header', false);
        $response->assertSee('Homepage Banner', false);
    }

    public function test_the_video_page_renders_the_video_banner(): void
    {
        $site = $this->bbwcamsSite();
        $cam = Cam::factory()->create(['tags' => ['bbw'], 'categories' => ['bbw']]);

        Ad::create([
            'site_id' => $site->id,
            'placement' => AdPlacement::VideoPageHeader->value,
            'advertiser' => 'Sample Advertiser',
            'campaign' => 'Video Run',
            'creative' => 'Video Banner',
            'destination_url' => 'https://advertiser.example/video',
            'image_path' => 'ads/video-banner.jpg',
            'alt_text' => 'Video Banner',
            'is_active' => true,
            'weight' => 10,
        ]);

        $response = $this->get('http://bbwcams.test/cam/'.$cam->username);

        $response->assertOk();
        $response->assertSee('ad-slot--video_page_header', false);
        $response->assertSee('Video Banner', false);
    }

    public function test_impressions_and_clicks_are_tracked_once_per_rendered_banner(): void
    {
        $site = $this->bbwcamsSite();

        Ad::create([
            'site_id' => $site->id,
            'placement' => AdPlacement::HomepageHeader->value,
            'advertiser' => 'Sample Advertiser',
            'campaign' => 'Homepage Run',
            'creative' => 'Tracked Banner',
            'destination_url' => 'https://advertiser.example/landing',
            'image_path' => 'ads/tracked-banner.jpg',
            'alt_text' => 'Tracked Banner',
            'is_active' => true,
            'weight' => 10,
        ]);

        $response = $this->withCookie(HomepageAbTest::COOKIE_NAME, HomepageAbTest::VARIANT_GRID)
            ->get('http://bbwcams.test/');

        $html = $response->getContent();

        preg_match('/<img[^>]*class="ad-slot__pixel"[\s\S]*?src="([^"]+)"/s', $html, $impressionMatch);
        preg_match('/<a[^>]*class="ad-slot__link"[\s\S]*?href="([^"]+)"/s', $html, $clickMatch);

        $this->assertArrayHasKey(1, $impressionMatch);
        $this->assertArrayHasKey(1, $clickMatch);

        $impressionUrl = html_entity_decode($impressionMatch[1]);
        $clickUrl = html_entity_decode($clickMatch[1]);

        $this->get($impressionUrl)->assertNoContent();
        $this->get($impressionUrl)->assertNoContent();

        $this->assertDatabaseCount('ad_impressions', 1);

        $this->get($clickUrl)->assertRedirect('https://advertiser.example/landing');
        $this->get($clickUrl)->assertRedirect('https://advertiser.example/landing');

        $this->assertDatabaseCount('ad_clicks', 1);
    }

    public function test_site_specific_ads_override_global_ads_for_the_same_placement(): void
    {
        $site = $this->bbwcamsSite();

        Ad::create([
            'site_id' => null,
            'placement' => AdPlacement::HomepageHeader->value,
            'advertiser' => 'Global Advertiser',
            'campaign' => 'Global Run',
            'creative' => 'Global Banner',
            'destination_url' => 'https://advertiser.example/global',
            'image_path' => 'ads/global-banner.jpg',
            'alt_text' => 'Global Banner',
            'is_active' => true,
            'weight' => 10,
        ]);

        Ad::create([
            'site_id' => $site->id,
            'placement' => AdPlacement::HomepageHeader->value,
            'advertiser' => 'Site Advertiser',
            'campaign' => 'Site Run',
            'creative' => 'Site Banner',
            'destination_url' => 'https://advertiser.example/site',
            'image_path' => 'ads/site-banner.jpg',
            'alt_text' => 'Site Banner',
            'is_active' => true,
            'weight' => 10,
        ]);

        $response = $this->withCookie(HomepageAbTest::COOKIE_NAME, HomepageAbTest::VARIANT_GRID)
            ->get('http://bbwcams.test/');

        $response->assertOk();
        $response->assertSee('Site Banner', false);
        $response->assertDontSee('Global Banner', false);
    }
}
