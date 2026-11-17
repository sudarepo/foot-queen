<?php

namespace Tests\Feature;

use App\Filament\Widgets\TrafficByCountryWidget;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Stats\GetAdStats;
use App\Mcp\Tools\Stats\GetTrafficStats;
use App\Models\Ad;
use App\Models\AdClick;
use App\Models\AdImpression;
use App\Models\Cam;
use App\Models\CamClickEvent;
use App\Models\PageViewEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\CountryDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PharData;
use Tests\TestCase;

/**
 * Visitor country on analytics events: where it comes from, and the
 * breakdowns built on it.
 */
class CountryTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const CLOUDFLARE_IP = '173.245.48.10';

    private const CLOUDFLARE_IPV6 = '2606:4700::1';

    private const DIRECT_IP = '203.0.113.5';

    protected function setUp(): void
    {
        parent::setUp();

        config(['geoip.database_path' => storage_path('framework/testing/missing-geoip.mmdb')]);
    }

    public function test_page_views_record_cloudflares_country_for_proxied_requests(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE_IP])
            ->withHeader('CF-IPCountry', 'de')
            ->get('/feed');

        $this->assertDatabaseHas('page_view_events', ['page' => 'feed', 'country' => 'DE']);
    }

    public function test_cloudflares_ipv6_edges_are_trusted_too(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE_IPV6])
            ->withHeader('CF-IPCountry', 'FR')
            ->get('/feed');

        $this->assertDatabaseHas('page_view_events', ['country' => 'FR']);
    }

    public function test_a_country_header_from_outside_cloudflare_is_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::DIRECT_IP])
            ->withHeader('CF-IPCountry', 'DE')
            ->get('/feed');

        $this->assertDatabaseHas('page_view_events', ['page' => 'feed', 'country' => null]);
    }

    public function test_cloudflares_unknown_and_tor_codes_are_not_stored_as_countries(): void
    {
        foreach (['XX', 'T1'] as $code) {
            $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE_IP])
                ->withHeader('CF-IPCountry', $code)
                ->get('/feed');
        }

        $this->assertSame(2, PageViewEvent::query()->whereNull('country')->count());
    }

    public function test_without_a_geoip_database_lookups_return_nothing(): void
    {
        $this->assertNull(app(CountryDetector::class)->lookup('8.8.8.8'));
    }

    public function test_outbound_cam_clicks_record_the_country(): void
    {
        $cam = Cam::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE_IP])
            ->withHeader('CF-IPCountry', 'GB')
            ->get(route('cams.redirect', [$cam, 'src' => 'grid']));

        $this->assertDatabaseHas('cam_click_events', ['cam_id' => $cam->id, 'country' => 'GB']);
    }

    public function test_ad_impressions_and_clicks_record_the_country(): void
    {
        $ad = $this->ad();
        $parameters = ['ad' => $ad->id, 'placement' => 'homepage_header', 'token' => (string) Str::uuid()];

        $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE_IP])
            ->withHeader('CF-IPCountry', 'CA')
            ->get(URL::temporarySignedRoute('ads.impressions.store', now()->addDay(), $parameters))
            ->assertNoContent();

        $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE_IP])
            ->withHeader('CF-IPCountry', 'CA')
            ->get(URL::temporarySignedRoute('ads.clicks.store', now()->addDay(), $parameters))
            ->assertRedirect();

        $this->assertDatabaseHas('ad_impressions', ['ad_id' => $ad->id, 'country' => 'CA']);
        $this->assertDatabaseHas('ad_clicks', ['ad_id' => $ad->id, 'country' => 'CA']);
    }

    public function test_country_names_come_from_iso_codes(): void
    {
        $this->assertSame('United States', CountryDetector::name('US'));
        $this->assertSame('Unknown', CountryDetector::name(null));
        $this->assertSame(['DE' => 'Germany', 'US' => 'United States'], CountryDetector::options(['US', null, 'DE', 'US']));
    }

    public function test_the_country_breakdown_counts_views_clicks_and_ctr_per_country(): void
    {
        $site = Site::factory()->create();
        $this->views($site, ['US' => 4, 'DE' => 2, null => 1]);
        $this->clicks($site, ['US' => 1, 'DE' => 1]);

        $rows = collect(TrafficByCountryWidget::breakdown([], null))->keyBy('label');

        $this->assertSame(['United States', 'Germany', 'Unknown'], $rows->keys()->all());
        $this->assertSame(['country' => 'US', 'pageviews' => 4, 'clicks' => 1, 'label' => 'United States', 'ctr' => 25.0], $rows['United States']);
        $this->assertSame(50.0, $rows['Germany']['ctr']);
        $this->assertSame(1, $rows['Unknown']['pageviews']);
    }

    public function test_the_country_breakdown_honours_the_page_filters_and_site_access(): void
    {
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        $this->views($site, ['US' => 3, 'DE' => 2]);
        $this->views($other, ['US' => 5]);

        $this->assertSame(
            [['country' => 'US', 'pageviews' => 3, 'clicks' => 0, 'label' => 'United States', 'ctr' => 0.0]],
            TrafficByCountryWidget::breakdown(['country' => 'US'], [$site->id]),
        );
    }

    public function test_the_country_breakdown_folds_the_long_tail_into_other(): void
    {
        $site = Site::factory()->create();
        $codes = ['US', 'DE', 'FR', 'GB', 'CA', 'AU', 'NL', 'BE', 'ES', 'IT', 'SE', 'NO', 'DK', 'FI', 'PL', 'BR', 'MX'];
        $this->views($site, collect($codes)->mapWithKeys(fn (string $code, int $index) => [$code => 20 - $index])->all());

        $rows = TrafficByCountryWidget::breakdown([], null);

        $this->assertCount(TrafficByCountryWidget::TOP_COUNTRIES + 1, $rows);
        $this->assertSame('Other', end($rows)['label']);
        $this->assertSame(5 + 4, end($rows)['pageviews']);
    }

    public function test_the_stats_dashboard_renders_with_a_country_filter(): void
    {
        $this->views(Site::factory()->create(), ['US' => 1]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/admin/stats-dashboard?filters[country]=US')
            ->assertOk()
            ->assertSee('Traffic by country');
    }

    public function test_mcp_traffic_stats_filter_and_break_down_by_country(): void
    {
        $site = Site::factory()->create();
        $this->views($site, ['US' => 2, 'DE' => 3]);
        $this->clicks($site, ['US' => 1]);

        AdminServer::actingAs(User::factory()->create())
            ->tool(GetTrafficStats::class, ['country' => 'us'])
            ->assertOk()
            ->assertSee(['"country":"US"', '"pageviews":2', '"outbound_clicks":1', '"ctr_percent":50']);

        AdminServer::actingAs(User::factory()->create())
            ->tool(GetTrafficStats::class)
            ->assertOk()
            ->assertSee(['"by_country"', '"DE":{"name":"Germany","pageviews":3']);
    }

    public function test_mcp_ad_stats_break_down_by_country(): void
    {
        $ad = $this->ad();
        $site = Site::factory()->create();

        foreach (['US', 'US', 'DE'] as $code) {
            AdImpression::query()->create(['ad_id' => $ad->id, 'site_id' => $site->id, 'country' => $code, 'placement' => 'homepage_header', 'token' => (string) Str::uuid()]);
        }
        AdClick::query()->create(['ad_id' => $ad->id, 'site_id' => $site->id, 'country' => 'US', 'placement' => 'homepage_header', 'token' => (string) Str::uuid()]);

        AdminServer::actingAs(User::factory()->create())
            ->tool(GetAdStats::class)
            ->assertOk()
            ->assertSee(['"US":{"name":"United States","impressions":2,"clicks":1,"ctr_percent":50']);

        AdminServer::actingAs(User::factory()->create())
            ->tool(GetAdStats::class, ['country' => 'DE'])
            ->assertOk()
            ->assertSee(['"impressions":1', '"clicks":0']);
    }

    public function test_geoip_update_skips_without_maxmind_credentials(): void
    {
        config(['geoip.maxmind_account_id' => null, 'geoip.maxmind_license_key' => null]);
        Http::fake();

        $this->artisan('geoip:update')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_geoip_update_installs_the_downloaded_database(): void
    {
        $destination = storage_path('framework/testing/geoip/GeoLite2-Country.mmdb');
        File::delete($destination);
        config([
            'geoip.maxmind_account_id' => '123',
            'geoip.maxmind_license_key' => 'secret',
            'geoip.database_path' => $destination,
        ]);

        Http::fake(['download.maxmind.com/*' => Http::response($this->tarball('fake-mmdb-contents'))]);

        $this->artisan('geoip:update')->assertSuccessful();

        $this->assertSame('fake-mmdb-contents', File::get($destination));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('123:secret')));

        File::deleteDirectory(dirname($destination));
    }

    public function test_geoip_update_fails_when_the_download_fails(): void
    {
        config(['geoip.maxmind_account_id' => '123', 'geoip.maxmind_license_key' => 'wrong']);
        Http::fake(['download.maxmind.com/*' => Http::response('Unauthorized', 401)]);

        $this->artisan('geoip:update')->assertFailed();
    }

    /**
     * @param  array<string, int>  $countsByCountry
     */
    private function views(Site $site, array $countsByCountry): void
    {
        foreach ($countsByCountry as $country => $count) {
            PageViewEvent::query()->insert(array_fill(0, $count, [
                'page' => 'grid', 'site_id' => $site->id, 'device' => 'desktop', 'country' => $country ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }

    /**
     * @param  array<string, int>  $countsByCountry
     */
    private function clicks(Site $site, array $countsByCountry): void
    {
        $cam = Cam::factory()->create();

        foreach ($countsByCountry as $country => $count) {
            CamClickEvent::query()->insert(array_fill(0, $count, [
                'cam_id' => $cam->id, 'source_page' => 'grid', 'site_id' => $site->id, 'device' => 'desktop', 'country' => $country,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }

    private function ad(): Ad
    {
        return Ad::query()->create([
            'placement' => 'homepage_header', 'advertiser' => 'Acme', 'campaign' => 'Fall', 'creative' => 'v1',
            'destination_url' => 'https://advertiser.example', 'image_path' => 'ads/a.jpg', 'is_active' => true, 'weight' => 1,
        ]);
    }

    /**
     * A tarball shaped like MaxMind's: the database inside a dated folder.
     */
    private function tarball(string $databaseContents): string
    {
        $directory = storage_path('framework/testing/geoip-build-'.Str::random(6));
        File::ensureDirectoryExists($directory);

        $tar = new PharData($directory.'/db.tar');
        $tar->addFromString('GeoLite2-Country_20261117/GeoLite2-Country.mmdb', $databaseContents);
        $tar->compress(\Phar::GZ);

        $contents = File::get($directory.'/db.tar.gz');
        File::deleteDirectory($directory);

        return $contents;
    }
}
