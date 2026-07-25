<?php

namespace Tests\Feature;

use App\Models\Cam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CamFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_the_grid_layout(): void
    {
        Cam::factory()->count(3)->create();

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertViewIs('cams.index');
    }

    public function test_feed_page_renders_the_same_online_cams_as_the_homepage(): void
    {
        Cam::factory()->count(3)->create(['is_online' => true]);
        Cam::factory()->create(['is_online' => false]);

        $response = $this->get('/feed');

        $response->assertStatus(200);
        $response->assertViewIs('cams.feed');
        $response->assertViewHas('cams', fn ($cams) => $cams->total() === 3);
    }

    public function test_feed_page_is_indexable_and_in_the_sitemap(): void
    {
        $this->get('/feed')
            ->assertStatus(200)
            ->assertSee('index,follow', false);

        $this->get('/sitemap.xml')->assertSee(url('/feed'), false);
    }
}
