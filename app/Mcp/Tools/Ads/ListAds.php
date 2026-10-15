<?php

namespace App\Mcp\Tools\Ads;

use App\Mcp\Support\SiteLookup;
use App\Mcp\Tools\AdminTool;
use App\Models\Ad;
use App\Services\AdPlacement;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List ads with their targeting, schedule and lifetime impression/click counts.')]
#[IsReadOnly]
class ListAds extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'site' => ['nullable'],
            'placement' => ['nullable', 'in:'.implode(',', array_column(AdPlacement::cases(), 'value'))],
            'active_only' => ['nullable', 'boolean'],
        ]);

        $site = SiteLookup::find($validated['site'] ?? null);

        if (filled($validated['site'] ?? null) && $site === null) {
            return Response::error("No site matches \"{$validated['site']}\".");
        }

        $ads = Ad::query()
            ->with('site:id,slug,name')
            ->withCount(['impressions', 'clicks'])
            ->when($site, fn ($query) => $query->where(fn ($query) => $query->whereNull('site_id')->orWhere('site_id', $site->id)))
            ->when($validated['placement'] ?? null, fn ($query, string $placement) => $query->where('placement', $placement))
            ->when($validated['active_only'] ?? false, fn ($query) => $query->where('is_active', true))
            ->latest()
            ->get();

        return Response::json($ads->map(fn (Ad $ad): array => [
            'id' => $ad->id,
            'site' => $ad->site?->only(['id', 'slug', 'name']) ?? 'all sites',
            'placement' => $ad->placement,
            'advertiser' => $ad->advertiser,
            'campaign' => $ad->campaign,
            'creative' => $ad->creative,
            'is_active' => $ad->is_active,
            'is_embed' => $ad->isEmbed(),
            'weight' => $ad->weight,
            'starts_at' => $ad->starts_at?->toIso8601String(),
            'ends_at' => $ad->ends_at?->toIso8601String(),
            'impressions' => $ad->impressions_count,
            'clicks' => $ad->clicks_count,
        ])->all());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('Site id or slug — lists ads that serve on it, including network-wide ones.'),
            'placement' => $schema->string()->enum(array_column(AdPlacement::cases(), 'value')),
            'active_only' => $schema->boolean()->default(false),
        ];
    }
}
