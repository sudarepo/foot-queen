<?php

namespace App\Http\Controllers;

use App\Models\Cam;
use App\Services\SeoPageResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CamController extends Controller
{
    public function __construct(private SeoPageResolver $seo) {}

    /**
     * Homepage — all cams, no preset filter. User can still apply their own filters.
     */
    public function index(Request $request): View
    {
        $filters = $this->parseFilters($request);

        return $this->renderGrid(
            filters:       $filters,
            userFilters:   $filters,
            h1:            'Live Cams',
            title:         'Live Cams — Watch Free Webcams Now',
            meta:          'Thousands of performers broadcasting live. Filter by gender, age, hair color, body type, and more.',
            canonicalUrl:  url('/'),
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
            throw new NotFoundHttpException("Unknown landing page.");
        }

        // User may additionally filter *on top of* the preset. Preset wins
        // where conflicts exist, because that's the SEO promise of the page.
        $userFilters = $this->parseFilters($request);
        $merged = array_merge($userFilters, $page['filters']);

        $canonicalUrl = url($this->seo->canonicalUrlFor($page));

        return $this->renderGrid(
            filters:      $merged,
            userFilters:  $userFilters,
            h1:           $page['h1'],
            title:        $page['title'],
            meta:         $page['meta'],
            canonicalUrl: $canonicalUrl,
        );
    }

    public function redirectToRoom(Cam $cam)
    {
        return redirect()->away($cam->room_url);
    }

    /**
     * Shared render path for both the homepage and all landing pages.
     */
    private function renderGrid(
        array $filters,
        array $userFilters,
        string $h1,
        string $title,
        string $meta,
        string $canonicalUrl,
    ): View {
        $cams = Cam::online()
            ->filter($filters)
            ->orderByDesc('viewers')
            ->paginate(48)
            ->withQueryString();

        return view('cams.index', [
            'cams'         => $cams,
            'filters'      => $userFilters,  // view only shows user-chosen filters in dropdowns
            'filterMeta'   => $this->filterMeta(),
            'totalOnline'  => Cam::online()->count(),
            'h1'           => $h1,
            'pageTitle'    => $title,
            'metaDesc'     => $meta,
            'canonicalUrl' => $canonicalUrl,
        ]);
    }

    private function parseFilters(Request $request): array
    {
        return array_filter([
            'gender'     => $request->query('gender'),
            'category'   => $request->query('category'),
            'age_range'  => $request->query('age'),
            'hair_color' => $request->query('hair'),
            'body_type'  => $request->query('body'),
            'hd'         => $request->query('hd') ? true : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function filterMeta(): array
    {
        return [
            'gender' => [
                ''       => 'All',
                'female' => 'Female',
                'male'   => 'Male',
                'trans'  => 'Trans',
                'couple' => 'Couples',
            ],
            'age' => [
                ''      => 'Any age',
                '18-22' => '18 – 22',
                '23-29' => '23 – 29',
                '30-39' => '30 – 39',
                '40-49' => '40 – 49',
                '50+'   => '50+',
            ],
            'hair' => [
                ''         => 'Any hair',
                'blonde'   => 'Blonde',
                'brunette' => 'Brunette',
                'black'    => 'Black',
                'red'      => 'Red',
                'other'    => 'Other',
            ],
            'body' => [
                ''         => 'Any body',
                'slim'     => 'Slim',
                'athletic' => 'Athletic',
                'average'  => 'Average',
                'curvy'    => 'Curvy',
                'bbw'      => 'BBW',
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
