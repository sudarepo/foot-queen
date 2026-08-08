<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTagTest extends TestCase
{
    use RefreshDatabase;

    private const FOOT_QUEEN_MEASUREMENT_ID = 'G-LYD5B7B36X';

    protected function setUp(): void
    {
        parent::setUp();

        // The tag is deliberately absent everywhere but production, so every
        // test here has to opt in to it.
        $this->app->detectEnvironment(fn () => 'production');
    }

    public function test_the_site_measurement_id_is_configured_on_the_page(): void
    {
        $response = $this->get('/feed');

        $response->assertSee('googletagmanager.com/gtag/js?id='.self::FOOT_QUEEN_MEASUREMENT_ID, false);
        $response->assertSee("gtag('config', '".self::FOOT_QUEEN_MEASUREMENT_ID."')", false);
    }

    /**
     * A GA property is per-domain. A site inheriting another's ID would merge
     * two audiences into one set of numbers, so a site without an ID of its
     * own gets no tag at all.
     */
    public function test_a_site_without_a_measurement_id_renders_no_tag(): void
    {
        Site::query()->first()->update(['ga_measurement_id' => null]);

        $this->get('/feed')
            ->assertDontSee('googletagmanager.com', false)
            ->assertDontSee(self::FOOT_QUEEN_MEASUREMENT_ID, false);
    }

    /**
     * Every non-production host resolves to the default site, so without this
     * gate local browsing would report into the live property — and a
     * developer refreshing a page is indistinguishable from a real visitor
     * once it's in there.
     */
    public function test_no_tag_is_rendered_outside_production(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->get('/feed')->assertDontSee('googletagmanager.com', false);
    }
}
