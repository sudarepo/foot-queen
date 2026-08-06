<?php

namespace App\Http\Controllers;

use App\Models\Cam;
use App\Models\CamClickEvent;
use App\Models\PageViewEvent;
use App\Services\CamProfileService;
use App\Services\HomepageAbTest;
use App\Services\SeoPageResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CamController extends Controller
{
    /**
     * This is a foot-cam site, so both listings start on the foot category
     * instead of everything the sync happens to have pulled in. The Chaturbate
     * sync is already tag-scoped ('feet', 'footfetish', 'toes' — see
     * ChaturbateProvider), but a performer pulled in under one of the narrower
     * tags doesn't necessarily carry 'feet' in `categories`, so the listing
     * applies the category itself rather than trusting the table.
     *
     * It's a default, not a lock: the category dropdown still switches away
     * from it. See withDefaultCategory().
     */
    private const DEFAULT_CATEGORY = 'feet';

    /**
     * Listings a visitor can arrive at a profile from. Anything else in
     * `?from=` is ignored rather than trusted into the analytics tables.
     */
    private const PROFILE_SOURCES = ['grid', 'feed'];

    public function __construct(
        private SeoPageResolver $seo,
        private HomepageAbTest $abTest,
        private CamProfileService $profiles,
    ) {}

    /**
     * Homepage. Real (non-bot) visitors are split 50/50 between the grid and
     * the feed variant via HomepageAbTest — the assignment is remembered by
     * cookie so it's consistent on repeat visits. Bots always see the grid
     * here with no cookie and no redirect, so this never affects SEO/crawling.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $userFilters = $this->parseFilters($request);

        if (! $this->abTest->isBot($request)) {
            [$variant, $isNewAssignment] = $this->abTest->resolve($request);

            if ($isNewAssignment) {
                Cookie::queue(HomepageAbTest::COOKIE_NAME, $variant, $this->abTest->cookieMinutes());
            }

            if ($variant === HomepageAbTest::VARIANT_FEED) {
                return redirect()->route('cams.feed', $request->query());
            }

            // Logged here (not in a shared spot) so a "/" → "/feed" redirect
            // above only ever counts once, at whichever page actually renders.
            PageViewEvent::create(['page' => HomepageAbTest::VARIANT_GRID]);
        }

        return $this->renderGrid(
            filters: $this->withDefaultCategory($request, $userFilters),
            userFilters: $userFilters,
            h1: 'Live Feet Cams',
            title: 'Live Feet Cams — Watch Free Foot Fetish Webcams Now',
            meta: 'Feet, soles, and toes live on cam right now. Filter by age, hair color, body type, and more.',
            canonicalUrl: url('/'),
        );
    }

    /**
     * Universal landing-page handler.
     * The slug comes from the route definition (set via ->defaults('slug', ...)
     * in routes/web.php), which is read from config/seo-pages.php at boot time.
     */
    public function landing(Request $request): View
    {
        $slug = $request->route()->defaults['slug'] ?? null;
        $page = $slug ? $this->seo->find($slug) : null;

        if ($page === null) {
            throw new NotFoundHttpException('Unknown landing page.');
        }

        // User may additionally filter *on top of* the preset. Preset wins
        // where conflicts exist, because that's the SEO promise of the page.
        $userFilters = $this->parseFilters($request);
        $merged = array_merge($userFilters, $page['filters']);

        $canonicalUrl = url($this->seo->canonicalUrlFor($page));

        return $this->renderGrid(
            filters: $merged,
            userFilters: $userFilters,
            h1: $page['h1'],
            title: $page['title'],
            meta: $page['meta'],
            canonicalUrl: $canonicalUrl,
        );
    }

    /**
     * Instagram-feed-style presentation of the same live cam data as the
     * homepage — a design variant served on its own URL so it can be
     * compared against the grid layout without touching "/". Reachable
     * directly (bookmarked/shared/organic) or via the A/B redirect from
     * index() — either way, a page view is logged exactly once here, at the
     * point content actually renders.
     */
    public function feed(Request $request): View|JsonResponse
    {
        $userFilters = $this->parseFilters($request);
        $filters = $this->withDefaultCategory($request, $userFilters);

        // Infinite scroll: pages after the first are fetched by the client and
        // appended, so only the post markup goes back — not a whole document,
        // and not another page view (the one logged on the initial render
        // already covers this visit, however far the user keeps scrolling).
        if ($request->boolean('partial')) {
            $cams = $this->onlineCams($filters);

            return response()->json([
                'html' => view('cams._feed-posts', ['cams' => $cams])->render(),
                'next_page_url' => $cams->nextPageUrl(),
            ]);
        }

        if (! $this->abTest->isBot($request)) {
            PageViewEvent::create(['page' => HomepageAbTest::VARIANT_FEED]);
        }

        return $this->renderGrid(
            filters: $filters,
            userFilters: $userFilters,
            h1: 'Live Feet Cams',
            title: 'Live Feet Cams — Watch Free Foot Fetish Webcams Now',
            meta: 'Feet, soles, and toes live on cam right now. Scroll the feed and tap in.',
            canonicalUrl: url('/feed'),
            view: 'cams.feed',
        );
    }

    /**
     * A performer's own page on our site: the live room embedded, plus the
     * bio and the Pics & Vids tab pulled from Chaturbate (see
     * CamProfileService). Everything on it that isn't navigation links out
     * through /go/, so the affiliate credit is the same as a listing click.
     *
     * Offline performers still get a page — the bio and photo sets are the
     * durable part, and a URL that 404s the moment someone stops streaming
     * is no use to a visitor who bookmarked it or a crawler that indexed it.
     */
    public function show(Request $request, Cam $cam): View
    {
        $this->ensureProfileFetched($cam);

        $source = $request->query('from');
        $source = in_array($source, self::PROFILE_SOURCES, strict: true) ? $source : null;

        if (! $this->abTest->isBot($request)) {
            PageViewEvent::create(['page' => 'profile']);

            // Arriving from a listing means a card on that listing was
            // clicked — the click just lands here now instead of on
            // Chaturbate. Logging it under the listing's own name keeps the
            // grid-vs-feed CTR comparison measuring the same thing it did
            // before profile pages existed. The onward click to Chaturbate
            // is logged separately, under 'profile'.
            if ($source !== null) {
                CamClickEvent::create([
                    'cam_id' => $cam->id,
                    'source_page' => $source,
                ]);
            }
        }

        $title = $cam->is_online
            ? "{$cam->username} — Live Feet Cam"
            : "{$cam->username} — Feet Cam Profile, Pics & Videos";

        return view('cams.show', [
            'cam' => $cam,
            'photoSets' => $cam->orderedPhotoSets(),
            'backTo' => $source === 'feed' ? 'feed' : 'grid',
            'totalOnline' => Cam::online()->count(),
            'pageTitle' => $title,
            'metaDesc' => $this->profileMetaDescription($cam),
            'canonicalUrl' => route('cams.show', $cam->username),
        ]);
    }

    /**
     * First view of a performer's page fetches their profile inline, so the
     * page is never bare just because the scheduled backfill
     * (`cams:sync-profiles`) hasn't reached them yet. Only ever for cams
     * never fetched before — once there's something to show, refreshing is
     * the backfill's job and no visitor waits on it.
     *
     * The cache key is a stampede guard: several visitors (or a crawler
     * working through the sitemap) hitting a cold page at once should
     * produce one outbound request, not one each. Whoever loses the race
     * renders without the profile rather than blocking on it.
     */
    private function ensureProfileFetched(Cam $cam): void
    {
        if (! $this->profiles->hasNeverBeenFetched($cam)) {
            return;
        }

        if (! Cache::add("cam-profile-fetch:{$cam->id}", true, now()->addMinutes(5))) {
            return;
        }

        $this->profiles->refresh($cam);
    }

    private function profileMetaDescription(Cam $cam): string
    {
        if (filled($cam->bio)) {
            return Str::limit(preg_replace('/\s+/', ' ', $cam->bio) ?? '', 155);
        }

        $traits = array_filter([
            $cam->age ? "{$cam->age}yo" : null,
            $cam->hair_color,
            $cam->body_type,
        ]);

        $description = "{$cam->username} — live feet and foot fetish cam";
        $description .= $traits !== [] ? ' ('.implode(', ', $traits).').' : '.';

        return $description.' Watch the live stream, browse photos and videos.';
    }

    public function redirectToRoom(Request $request, Cam $cam): RedirectResponse
    {
        // 'profile' is every outbound link on a performer's own page — the
        // embed, the photo sets, the CTA. 'admin' covers the "Visit Room"
        // action in the Filament cam resource, so those outbound clicks are
        // tracked too, not just the public pages.
        $source = $request->query('src');
        $source = in_array($source, ['grid', 'feed', 'profile', 'admin'], strict: true) ? $source : 'grid';

        // The redirect itself always happens, bot or not — no reason to block
        // it. Only the click *log* is bot-gated, to match how page views are
        // filtered (index()/feed() above). Without this, a crawler that
        // follows /go/ links (robots.txt disallows it, but not everything
        // respects that) inflates click counts with no matching filtered
        // view, which is exactly what produced a 1200%+ CTR on grid in
        // practice — clicks with no bot filter at all, divided by views that
        // were already filtered.
        if (! $this->abTest->isBot($request)) {
            CamClickEvent::create([
                'cam_id' => $cam->id,
                'source_page' => $source,
            ]);
        }

        return redirect()->away($this->trackedRoomUrl($cam->room_url, $source));
    }

    /**
     * Chaturbate reports affiliate revenue/conversions broken out by the
     * `track` query param on the outbound URL — it's a free-form sub-id,
     * not something that has to be pre-registered on their side. We
     * override whatever `track` value Chaturbate's API baked into
     * `room_url` at sync time with our own `$source` label so grid vs.
     * feed revenue is visible in Chaturbate's own affiliate dashboard,
     * matching the `source_page` label used for our in-app click/view
     * analytics.
     */
    private function trackedRoomUrl(string $roomUrl, string $source): string
    {
        $parts = parse_url($roomUrl);
        if ($parts === false || empty($parts['host'])) {
            return $roomUrl;
        }

        parse_str($parts['query'] ?? '', $query);
        $query['track'] = $source;

        $scheme = $parts['scheme'] ?? 'https';
        $path = $parts['path'] ?? '/';

        return "{$scheme}://{$parts['host']}{$path}?".http_build_query($query);
    }

    /**
     * Shared render path for the homepage, all landing pages, and the feed variant.
     */
    private function renderGrid(
        array $filters,
        array $userFilters,
        string $h1,
        string $title,
        string $meta,
        string $canonicalUrl,
        string $view = 'cams.index',
    ): View {
        $cams = $this->onlineCams($filters);

        return view($view, [
            'cams' => $cams,
            // Dropdowns show what the listing is *actually* filtered by —
            // including the default category and any landing-page preset — so
            // the bar never claims "All categories" over a narrowed listing.
            // `userFilters` is only what the visitor picked, which is what the
            // "Clear" link needs to know about.
            'filters' => $filters,
            'userFilters' => $userFilters,
            'filterMeta' => $this->filterMeta(),
            'totalOnline' => Cam::online()->count(),
            'h1' => $h1,
            'pageTitle' => $title,
            'metaDesc' => $meta,
            'canonicalUrl' => $canonicalUrl,
        ]);
    }

    /**
     * The one query behind every listing — grid, landing pages, and both the
     * initial render and the infinite-scroll batches of the feed.
     *
     * Filters are carried into the pagination links, but `partial` is not:
     * it's a transport detail of the scroll fetch, not part of the page's
     * identity, and keeping it would bake it into every "next page" URL.
     *
     * @return LengthAwarePaginator<int, Cam>
     */
    private function onlineCams(array $filters): LengthAwarePaginator
    {
        return Cam::online()
            ->filter($filters)
            ->orderByDesc('viewers')
            ->paginate(48)
            ->appends(Arr::except(request()->query(), ['page', 'partial']));
    }

    /**
     * Applies the site's default category to a filter set.
     *
     * Only fills in a category the visitor never expressed an opinion about.
     * Picking "All categories" in the filter bar submits an empty `category=`,
     * which is a deliberate choice — it clears the default rather than falling
     * back to it, so the dropdown can actually leave the foot category.
     */
    private function withDefaultCategory(Request $request, array $filters): array
    {
        if (! $request->has('category')) {
            $filters['category'] = self::DEFAULT_CATEGORY;
        }

        return $filters;
    }

    private function parseFilters(Request $request): array
    {
        return array_filter([
            'gender' => $request->query('gender'),
            'category' => $request->query('category'),
            'age_range' => $request->query('age'),
            'hair_color' => $request->query('hair'),
            'body_type' => $request->query('body'),
            'hd' => $request->query('hd') ? true : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function filterMeta(): array
    {
        return [
            'gender' => [
                '' => 'All',
                'female' => 'Female',
                'male' => 'Male',
                'trans' => 'Trans',
                'couple' => 'Couples',
            ],
            'age' => [
                '' => 'Any age',
                '18-22' => '18 – 22',
                '23-29' => '23 – 29',
                '30-39' => '30 – 39',
                '40-49' => '40 – 49',
                '50+' => '50+',
            ],
            'hair' => [
                '' => 'Any hair',
                'blonde' => 'Blonde',
                'brunette' => 'Brunette',
                'black' => 'Black',
                'red' => 'Red',
                'other' => 'Other',
            ],
            'body' => [
                '' => 'Any body',
                'slim' => 'Slim',
                'athletic' => 'Athletic',
                'average' => 'Average',
                'curvy' => 'Curvy',
                'bbw' => 'BBW',
            ],
            'category' => array_merge(
                ['' => 'All categories'],
                array_combine(
                    config('cam-taxonomy.featured_categories'),
                    array_map('ucfirst', config('cam-taxonomy.featured_categories'))
                )
            ),
        ];
    }
}
