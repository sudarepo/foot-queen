<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_can_manage_ads(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);

        /** @var Site $site */
        $site = Site::factory()->create();

        /** @var Ad $ad */
        $ad = Ad::create([
            'site_id' => $site->id,
            'placement' => 'homepage_header',
            'advertiser' => 'Acme Media',
            'campaign' => 'Launch Week',
            'creative' => 'Homepage Banner',
            'destination_url' => 'https://example.test/landing',
            'image_path' => 'ads/homepage-banner.jpg',
            'is_active' => true,
            'weight' => 1,
        ]);

        $this->actingAs($admin)
            ->get('/admin/ads')
            ->assertSuccessful()
            ->assertSee('Ads', false)
            ->assertSee('Add ad', false)
            ->assertSee('Impressions', false)
            ->assertSee('Clicks', false);

        $this->actingAs($admin)->get('/admin/ads/create')->assertSuccessful();
        $this->actingAs($admin)->get("/admin/ads/{$ad->id}/edit")->assertSuccessful();
    }

    public function test_site_managers_do_not_see_the_ads_resource(): void
    {
        /** @var Site $site */
        $site = Site::query()->where('is_default', true)->firstOrFail();

        /** @var User $manager */
        $manager = User::factory()->siteManager($site)->create();

        $this->actingAs($manager)
            ->get('/admin')
            ->assertSuccessful()
            ->assertDontSee('Ads', false);
    }
}
