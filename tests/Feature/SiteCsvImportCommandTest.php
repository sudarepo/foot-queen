<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteCsvImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_new_sites_and_skips_duplicates(): void
    {
        $initialCount = Site::query()->count('*');

        Site::factory()->create([
            'slug' => 'existing-site',
            'domains' => ['existing.com'],
        ]);

        $csvPath = $this->writeCsv([
            [
                'site_name',
                'Slug',
                'Domains',
                'Active / Default SIte',
                'Desktop Visitors',
                'Mobile Visitors',
                'Gender',
                'Keywords / tags',
                'Default category',
                'homepage_heading',
                'homepage <title>',
                'tagline',
                'homepage_meta_description',
                'feed_meta_description',
                'meta keywords',
                'profile_title_live',
                'profile_title_offline',
                'profile_description_phrase',
                'SEO pages registry',
                'niche_detected',
                'Google Analytics measurement ID',
            ],
            [
                'My Import Site',
                '',
                'newsite.com',
                'Active',
                'Feed',
                'Grid',
                'Female',
                'tag-one, tag-two',
                'voyeur',
                'Live Voyeur Cams',
                'Voyeur Cams - Live',
                'Watch now',
                'Homepage description',
                'Feed description',
                'voyeur, cams',
                'Live Voyeur Show',
                'Voyeur Show - Offline',
                'live voyeur show',
                'foot-queen',
                'voyeur',
                'G-TEST123456',
            ],
            [
                'Duplicate Domain Site',
                '',
                'existing.com',
                'Active',
                'Feed',
                'Feed',
                'Female',
                'dup-tag',
                'general',
                'Heading',
                'Title',
                'Tagline',
                'Meta',
                'Feed Meta',
                'keywords',
                'Live',
                'Offline',
                'phrase',
                'foot-queen',
                'general',
                'G-DUPLICATE1',
            ],
        ]);

        $this->artisan('sites:import-csv', ['path' => $csvPath])
            ->expectsOutputToContain('created My Import Site')
            ->expectsOutputToContain('skipped (duplicate domain or slug)')
            ->assertExitCode(0);

        $site = Site::query()->where('slug', 'my-import-site')->first();

        $this->assertNotNull($site);
        $this->assertSame(['newsite.com'], $site->domains);
        $this->assertSame(['tag-one', 'tag-two'], $site->tags);
        $this->assertSame('feed', $site->home_layout_desktop->value);
        $this->assertSame('grid', $site->home_layout_mobile->value);
        $this->assertSame('foot-queen', $site->seo_pages);
        $this->assertSame('G-TEST123456', $site->ga_measurement_id);

        $this->assertSame($initialCount + 2, Site::query()->count('*'));
    }

    public function test_dry_run_does_not_write_to_database(): void
    {
        $initialCount = Site::query()->count('*');

        $csvPath = $this->writeCsv([
            [
                'site_name',
                'Slug',
                'Domains',
                'Active / Default SIte',
                'Desktop Visitors',
                'Mobile Visitors',
                'Gender',
                'Keywords / tags',
                'Default category',
                'homepage_heading',
                'homepage <title>',
                'tagline',
                'homepage_meta_description',
                'feed_meta_description',
                'meta keywords',
                'profile_title_live',
                'profile_title_offline',
                'profile_description_phrase',
                'SEO pages registry',
                'niche_detected',
                'Google Analytics measurement ID',
            ],
            [
                'Dry Run Site',
                '',
                'dry-run.example',
                'Active',
                'Feed',
                'Feed',
                'Female',
                'one, two',
                'general',
                'Heading',
                'Title',
                'Tagline',
                'Meta',
                'Feed Meta',
                'keywords',
                'Live',
                'Offline',
                'phrase',
                'foot-queen',
                'general',
                'G-DRYRUN1234',
            ],
        ]);

        $this->artisan('sites:import-csv', ['path' => $csvPath, '--dry-run' => true])
            ->expectsOutputToContain('would create Dry Run Site')
            ->assertExitCode(0);

        $this->assertSame($initialCount, Site::query()->count('*'));
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function writeCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'site-import-');
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }
}
