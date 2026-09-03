<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\HomepageAbTest;
use App\Services\LegalPage;
use App\Services\LegalPageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four pages every domain has to carry.
 *
 * Two things are being protected here: that all four exist and are reachable
 * on every site without anyone configuring anything, and that a site which
 * rewrites one of them changes only that page, on only that site.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private function footQueen(): Site
    {
        return Site::query()->where('slug', 'foot-queen')->firstOrFail();
    }

    /**
     * An absolute URL on the default site's host — the tests below mix hosts,
     * and a relative path silently stays on whichever domain was requested
     * last (see MultiSiteTest::defaultHost).
     */
    private function defaultHost(string $path): string
    {
        return rtrim(config('app.url'), '/').$path;
    }

    /* ----------  The pages exist  ---------- */

    public function test_every_legal_page_is_served_with_its_default_text(): void
    {
        foreach (LegalPage::all() as $page) {
            $response = $this->get($this->defaultHost('/'.$page->slug()));

            $response->assertOk();
            $response->assertSee($page->title());
            $response->assertSee('Foot Queen Cams', escape: false);
        }
    }

    public function test_the_2257_page_is_served_on_the_conventional_bare_number_url(): void
    {
        $this->get($this->defaultHost('/2257'))
            ->assertOk()
            ->assertSee('18 U.S.C. § 2257', escape: false);

        // The storage key is never a URL.
        $this->get($this->defaultHost('/usc-2257'))->assertNotFound();
    }

    public function test_each_page_carries_its_own_title_canonical_and_description(): void
    {
        $response = $this->get($this->defaultHost('/dmca'));

        $response->assertSee('<title>DMCA Notice &amp; Takedown Policy — Foot Queen Cams</title>', escape: false);
        $response->assertSee('<link rel="canonical" href="'.$this->defaultHost('/dmca').'">', escape: false);
        $response->assertSee(LegalPage::Dmca->metaDescription(), escape: false);
    }

    public function test_the_default_text_names_the_site_its_domain_and_its_contact_address(): void
    {
        $site = Site::factory()->onDomain('bbwcams.test')->create(['name' => 'BBW Cams']);

        $response = $this->get('http://bbwcams.test/dmca');

        $response->assertOk();
        $response->assertSee('BBW Cams');
        $response->assertSee('bbwcams.test');
        // Derived from the primary domain when no address is configured.
        $response->assertSee('abuse@bbwcams.test');
    }

    public function test_a_configured_contact_address_replaces_the_derived_one(): void
    {
        Site::factory()->onDomain('bbwcams.test')->create([
            'legal_contact_email' => 'legal@example.com',
        ]);

        $response = $this->get('http://bbwcams.test/privacy-policy');

        $response->assertSee('legal@example.com');
        $response->assertDontSee('abuse@bbwcams.test');
    }

    /* ----------  Footer links  ---------- */

    public function test_every_page_of_the_site_links_to_all_four_in_the_footer(): void
    {
        $response = $this->withCookie(HomepageAbTest::COOKIE_NAME, HomepageAbTest::VARIANT_GRID)
            ->get($this->defaultHost('/'));

        $response->assertOk();

        foreach (LegalPage::all() as $page) {
            $response->assertSee('href="'.route($page->routeName()).'"', escape: false);
            $response->assertSee($page->footerLabel(), escape: false);
        }
    }

    public function test_footer_links_stay_on_the_domain_being_viewed(): void
    {
        Site::factory()->onDomain('bbwcams.test')->create();

        $this->get('http://bbwcams.test/2257')
            ->assertSee('href="http://bbwcams.test/dmca"', escape: false);
    }

    /* ----------  Overrides  ---------- */

    public function test_a_site_can_rewrite_a_pages_text_and_heading(): void
    {
        $site = $this->footQueen();

        $site->update(['legal_pages' => [
            LegalPage::Terms->value => [
                'title' => 'House Rules',
                'body' => '<p>Be excellent to each other.</p>',
            ],
        ]]);

        $response = $this->get($this->defaultHost('/terms-and-conditions'));

        $response->assertSee('House Rules');
        $response->assertSee('Be excellent to each other.');
        $response->assertDontSee('Affiliate disclosure');

        // The footer label is fixed, so the link keeps working regardless.
        $response->assertSee('Terms and Conditions');
    }

    public function test_an_override_touches_only_that_page_on_only_that_site(): void
    {
        Site::factory()->onDomain('bbwcams.test')->create([
            'name' => 'BBW Cams',
            'legal_pages' => [
                LegalPage::Dmca->value => ['body' => '<p>Our own takedown policy.</p>'],
            ],
        ]);

        $this->get('http://bbwcams.test/dmca')->assertSee('Our own takedown policy.');

        // Same site, different page: still the standard text.
        $this->get('http://bbwcams.test/privacy-policy')->assertSee('The short version');

        // Different site, same page: untouched.
        $this->get($this->defaultHost('/dmca'))
            ->assertDontSee('Our own takedown policy.')
            ->assertSee('Sending a notice of claimed infringement');
    }

    public function test_an_emptied_override_falls_back_to_the_standard_text(): void
    {
        $site = $this->footQueen();

        $site->update(['legal_pages' => [
            LegalPage::Privacy->value => ['title' => '', 'body' => ''],
        ]]);

        $this->get($this->defaultHost('/privacy-policy'))
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('The short version');
    }

    /**
     * Rows written before SiteForm started stripping empty markup, or by
     * anything else that writes this column.
     */
    public function test_markup_with_no_text_in_it_is_not_treated_as_an_override(): void
    {
        $this->footQueen()->update(['legal_pages' => [
            LegalPage::Dmca->value => ['title' => '  ', 'body' => '<p></p>'],
            LegalPage::Terms->value => ['body' => '<p>&nbsp;</p>'],
        ]]);

        $this->get($this->defaultHost('/dmca'))
            ->assertOk()
            ->assertSee('DMCA Notice')
            ->assertSee('Sending a notice of claimed infringement');

        $this->get($this->defaultHost('/terms-and-conditions'))
            ->assertOk()
            ->assertSee('You must be an adult');
    }

    public function test_an_override_is_sanitised_before_it_reaches_the_page(): void
    {
        $this->footQueen()->update(['legal_pages' => [
            LegalPage::Terms->value => [
                'body' => '<p>Fine print.</p><script>alert(1)</script>',
            ],
        ]]);

        $response = $this->get($this->defaultHost('/terms-and-conditions'));

        $response->assertSee('Fine print.');
        $response->assertDontSee('<script>alert(1)</script>', escape: false);
    }

    /* ----------  Discoverability  ---------- */

    public function test_the_sitemap_lists_all_four_pages(): void
    {
        $response = $this->get($this->defaultHost('/sitemap.xml'));

        foreach (LegalPage::all() as $page) {
            $response->assertSee('<loc>'.$this->defaultHost('/'.$page->slug()).'</loc>', escape: false);
        }
    }

    /* ----------  Resolver  ---------- */

    public function test_the_resolver_reports_whether_a_site_has_rewritten_a_page(): void
    {
        $resolver = app(LegalPageResolver::class);
        $site = $this->footQueen();

        $this->assertFalse($resolver->isOverridden($site, LegalPage::Dmca));

        $site->update(['legal_pages' => [
            LegalPage::Dmca->value => ['body' => '<p>Ours.</p>'],
        ]]);

        $this->assertTrue($resolver->isOverridden($site->fresh(), LegalPage::Dmca));
        $this->assertFalse($resolver->isOverridden($site->fresh(), LegalPage::Terms));
    }

    public function test_a_site_with_no_domains_still_renders_readable_default_text(): void
    {
        $site = Site::factory()->create(['domains' => [], 'name' => 'Nameless']);

        $body = app(LegalPageResolver::class)->defaultBody($site, LegalPage::Usc2257);

        $this->assertStringContainsString('Nameless', $body);
        $this->assertStringNotContainsString('{{', $body);
    }
}
