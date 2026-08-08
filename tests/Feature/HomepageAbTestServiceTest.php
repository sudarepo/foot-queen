<?php

namespace Tests\Feature;

use App\Services\HomepageAbTest;
use App\Services\HomepageLayout;
use Illuminate\Http\Request;
use Tests\TestCase;

class HomepageAbTestServiceTest extends TestCase
{
    private function requestWithUserAgent(?string $userAgent): Request
    {
        $request = Request::create('/');

        // Request::create() defaults HTTP_USER_AGENT to the literal string
        // "Symfony" when none is given — clear it explicitly to represent a
        // genuinely missing header instead.
        if ($userAgent === null) {
            $request->headers->remove('User-Agent');
        } else {
            $request->headers->set('User-Agent', $userAgent);
        }

        return $request;
    }

    public function test_it_detects_common_bots(): void
    {
        $service = new HomepageAbTest;

        $this->assertTrue($service->isBot($this->requestWithUserAgent(
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
        )));
        $this->assertTrue($service->isBot($this->requestWithUserAgent(
            'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'
        )));
        $this->assertTrue($service->isBot($this->requestWithUserAgent(null)));
    }

    public function test_it_does_not_flag_ordinary_browsers_as_bots(): void
    {
        $service = new HomepageAbTest;

        $this->assertFalse($service->isBot($this->requestWithUserAgent(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15'
        )));
        $this->assertFalse($service->isBot($this->requestWithUserAgent(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0.0.0'
        )));
    }

    public function test_it_honors_an_existing_valid_cookie(): void
    {
        $service = new HomepageAbTest;

        $request = Request::create('/', 'GET', cookies: [HomepageAbTest::COOKIE_NAME => 'grid']);
        $this->assertSame(['grid', false], $service->resolve($request));

        $request = Request::create('/', 'GET', cookies: [HomepageAbTest::COOKIE_NAME => 'feed']);
        $this->assertSame(['feed', false], $service->resolve($request));
    }

    public function test_it_assigns_a_random_variant_when_theres_no_valid_cookie(): void
    {
        $service = new HomepageAbTest;

        [$variant, $isNew] = $service->resolve(Request::create('/'));
        $this->assertContains($variant, ['grid', 'feed']);
        $this->assertTrue($isNew);

        // A tampered/garbage cookie value is treated the same as no cookie at all.
        $request = Request::create('/', 'GET', cookies: [HomepageAbTest::COOKIE_NAME => 'nonsense']);
        [$variant, $isNew] = $service->resolve($request);
        $this->assertContains($variant, ['grid', 'feed']);
        $this->assertTrue($isNew);
    }

    public function test_a_pinned_layout_wins_over_any_cookie_and_assigns_nothing(): void
    {
        $service = new HomepageAbTest;

        $request = Request::create('/', 'GET', cookies: [HomepageAbTest::COOKIE_NAME => 'feed']);
        $this->assertSame(['grid', false], $service->resolve($request, HomepageLayout::Grid));

        $request = Request::create('/', 'GET', cookies: [HomepageAbTest::COOKIE_NAME => 'grid']);
        $this->assertSame(['feed', false], $service->resolve($request, HomepageLayout::Feed));
    }

    public function test_a_pinned_layout_is_served_to_a_visitor_with_no_cookie_at_all(): void
    {
        $service = new HomepageAbTest;

        // Never "assigned": pinning isn't an experiment arm, so there's
        // nothing to remember — and nothing to seed a later experiment with.
        $this->assertSame(['feed', false], $service->resolve(Request::create('/'), HomepageLayout::Feed));
    }

    public function test_the_ab_test_layout_splits_exactly_as_it_did_before(): void
    {
        $service = new HomepageAbTest;

        $request = Request::create('/', 'GET', cookies: [HomepageAbTest::COOKIE_NAME => 'feed']);
        $this->assertSame(['feed', false], $service->resolve($request, HomepageLayout::AbTest));

        [$variant, $isNew] = $service->resolve(Request::create('/'), HomepageLayout::AbTest);
        $this->assertContains($variant, ['grid', 'feed']);
        $this->assertTrue($isNew);
    }

    public function test_random_assignment_produces_both_variants_over_many_runs(): void
    {
        $service = new HomepageAbTest;
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            [$variant] = $service->resolve(Request::create('/'));
            $seen[$variant] = true;
        }

        $this->assertArrayHasKey('grid', $seen);
        $this->assertArrayHasKey('feed', $seen);
    }
}
