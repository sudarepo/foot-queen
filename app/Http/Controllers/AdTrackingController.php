<?php

namespace App\Http\Controllers;

use App\Models\Ad;
use App\Models\AdClick;
use App\Models\AdImpression;
use App\Models\Site;
use App\Services\AdPlacement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AdTrackingController extends Controller
{
    public function impression(Request $request, Ad $ad, string $placement, string $token): Response
    {
        $this->ensureSignedRequest($request, $ad, $placement);

        if ($this->recordOnce('ad-impression:'.$token)) {
            AdImpression::create([
                'ad_id' => $ad->getKey(),
                'site_id' => app(Site::class)->getKey(),
                'placement' => $placement,
                'token' => $token,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 255),
            ]);
        }

        return response('', SymfonyResponse::HTTP_NO_CONTENT, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, Ad $ad, string $placement, string $token): Response|RedirectResponse
    {
        $this->ensureSignedRequest($request, $ad, $placement);

        if ($this->recordOnce('ad-click:'.$token)) {
            AdClick::create([
                'ad_id' => $ad->getKey(),
                'site_id' => app(Site::class)->getKey(),
                'placement' => $placement,
                'token' => $token,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 255),
            ]);
        }

        return redirect()->away($ad->destination_url);
    }

    private function ensureSignedRequest(Request $request, Ad $ad, string $placement): void
    {
        abort_unless($request->hasValidSignature(), 403);

        $expectedPlacement = AdPlacement::tryFrom($placement);

        abort_if($expectedPlacement === null, 404);
        abort_if($ad->placement !== $expectedPlacement->value, 404);
    }

    private function recordOnce(string $cacheKey): bool
    {
        return Cache::add($cacheKey, true, now()->addDay());
    }
}
