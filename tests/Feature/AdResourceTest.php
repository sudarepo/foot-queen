<?php

namespace Tests\Feature;

use App\Filament\Resources\Ads\Pages\ListAds;
use App\Models\Ad;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\ReplicateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_admins_can_duplicate_an_ad_campaign(): void
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

        $this->actingAs($admin);

        Livewire::test(ListAds::class)
            ->callAction(TestAction::make(ReplicateAction::class)->table($ad))
            ->assertNotified();

        $this->assertDatabaseCount('ads', 2);

        /** @var Ad $duplicate */
        $duplicate = Ad::query()->whereKeyNot($ad->id)->firstOrFail();

        $this->assertSame($ad->site_id, $duplicate->site_id);
        $this->assertSame($ad->placement, $duplicate->placement);
        $this->assertSame($ad->advertiser, $duplicate->advertiser);
        $this->assertSame('Launch Week (Copy)', $duplicate->campaign);
        $this->assertSame('Homepage Banner (Copy)', $duplicate->creative);
        $this->assertSame($ad->destination_url, $duplicate->destination_url);
        $this->assertSame($ad->image_path, $duplicate->image_path);
        $this->assertSame($ad->is_active, $duplicate->is_active);
        $this->assertSame($ad->weight, $duplicate->weight);
    }
}
