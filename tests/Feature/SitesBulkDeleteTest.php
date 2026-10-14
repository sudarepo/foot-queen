<?php

namespace Tests\Feature;

use App\Filament\Resources\Sites\Pages\ListSites;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SitesBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_bulk_soft_delete_sites(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $doomed = Site::factory()->count(2)->create();
        $kept = Site::factory()->create();

        Livewire::test(ListSites::class)
            ->selectTableRecords($doomed)
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
            ->assertNotified();

        foreach ($doomed as $site) {
            $this->assertSoftDeleted($site);
        }

        $this->assertNotSoftDeleted($kept);
    }

    public function test_soft_deleted_sites_no_longer_resolve_by_domain(): void
    {
        $fallback = Site::factory()->create(['is_default' => true]);
        $deleted = Site::factory()->create(['domains' => ['gone.example']]);

        $deleted->delete();

        $this->assertTrue(Site::resolveForHost('gone.example')->is($fallback));
    }

    public function test_an_admin_can_bulk_restore_soft_deleted_sites(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $sites = Site::factory()->count(2)->create();
        $sites->each->delete();

        Livewire::test(ListSites::class)
            ->filterTable('trashed', false)
            ->selectTableRecords($sites)
            ->callAction(TestAction::make(RestoreBulkAction::class)->table()->bulk());

        foreach ($sites as $site) {
            $this->assertNotSoftDeleted($site);
        }
    }

    public function test_a_site_manager_cannot_bulk_delete_sites(): void
    {
        $site = Site::factory()->create();
        $this->actingAs(User::factory()->siteManager($site)->create());

        Livewire::test(ListSites::class)
            ->assertActionHidden(TestAction::make(DeleteBulkAction::class)->table()->bulk());

        $this->assertNotSoftDeleted($site);
    }
}
