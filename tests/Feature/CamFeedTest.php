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

    public function test_feed_cards_expose_a_hover_preview_hook_only_when_an_embed_url_exists(): void
    {
        Cam::factory()->create([
            'username' => 'has-preview',
            'embed_url' => 'https://chaturbate.com/in/?tour=abc&campaign=Vg4Qi&track=embed&room=has-preview&disable_sound=1',
        ]);
        Cam::factory()->create(['username' => 'no-preview', 'embed_url' => null]);

        $response = $this->get('/feed');

        $response->assertSee('data-embed-url="https://chaturbate.com/in/?tour=abc&amp;campaign=Vg4Qi&amp;track=embed&amp;room=has-preview&amp;disable_sound=1"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'Live preview'));
    }
}
