<?php

namespace App\Mcp\Support;

use App\Models\Ad;
use App\Services\AdPlacement;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The editable fields of an Ad, shared by create-ad and update-ad. Mirrors
 * AdForm: an ad is either a tracked image banner (image + destination URL)
 * or a third-party embed tag.
 */
class AdFields
{
    /**
     * @return array<string, Type>
     */
    public static function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->nullable()->description('Site id or slug to target; null runs the ad on every site.'),
            'placement' => $schema->string()->enum(array_column(AdPlacement::cases(), 'value'))->description('Where the banner shows.'),
            'is_active' => $schema->boolean()->description('Whether the ad is eligible to serve.'),
            'weight' => $schema->integer()->description('Relative rotation weight among ads in the same placement (min 1).'),
            'advertiser' => $schema->string()->description('Advertiser name (max 120).'),
            'campaign' => $schema->string()->description('Campaign name (max 120).'),
            'creative' => $schema->string()->description('Creative name (max 120).'),
            'embed_code' => $schema->string()->nullable()->description('Third-party ad tag rendered as-is instead of a tracked image banner. Leave null for an image banner.'),
            'destination_url' => $schema->string()->nullable()->description('Click-through URL for an image banner. Required unless embed_code is set.'),
            'image_url' => $schema->string()->description('URL of the banner image to download (PNG/JPEG/GIF/WebP). Required on create unless embed_code is set.'),
            'alt_text' => $schema->string()->nullable()->description('Banner alt text (max 160).'),
            'starts_at' => $schema->string()->nullable()->description('ISO 8601 date-time the ad starts serving; null for immediately.'),
            'ends_at' => $schema->string()->nullable()->description('ISO 8601 date-time the ad stops serving; null for never.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(?Ad $ad = null): array
    {
        $creating = $ad === null;

        return [
            'site' => ['sometimes', 'nullable'],
            'placement' => [$creating ? 'required' : 'sometimes', Rule::enum(AdPlacement::class)],
            'is_active' => ['sometimes', 'boolean'],
            'weight' => ['sometimes', 'integer', 'min:1'],
            'advertiser' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'campaign' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'creative' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'embed_code' => ['sometimes', 'nullable', 'string'],
            'destination_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'image_url' => ['sometimes', 'url'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:160'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    /**
     * Turns validated input into model attributes, downloading the banner
     * and resolving the target site.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the site doesn't exist or the image can't be fetched.
     */
    public static function toAttributes(array $validated): array
    {
        $attributes = Arr::except($validated, ['site', 'image_url']);

        if (array_key_exists('site', $validated)) {
            $site = SiteLookup::find($validated['site']);

            if (filled($validated['site']) && $site === null) {
                throw new RuntimeException("No site matches \"{$validated['site']}\".");
            }

            $attributes['site_id'] = $site?->getKey();
        }

        if (filled($validated['image_url'] ?? null)) {
            $attributes['image_path'] = RemoteImage::store($validated['image_url'], 'ads', ['image/png', 'image/jpeg', 'image/gif', 'image/webp']);
        }

        return $attributes;
    }

    /**
     * What AdForm enforces with its conditional `required`s: without an embed
     * tag, an ad is a banner and needs both an image and somewhere to go.
     * Checked against the ad as it would be saved, so an update that drops
     * the embed code from a banner-less ad is caught too.
     */
    public static function incompleteBannerError(Ad $ad): ?string
    {
        if ($ad->isEmbed()) {
            return null;
        }

        $missing = array_filter([
            blank($ad->image_path) ? 'image_url' : null,
            blank($ad->destination_url) ? 'destination_url' : null,
        ]);

        return $missing === []
            ? null
            : 'An ad without embed_code is an image banner and needs '.implode(' and ', $missing).'.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Ad $ad): array
    {
        return [
            ...Arr::except($ad->attributesToArray(), ['site']),
            'site' => $ad->site?->only(['id', 'slug', 'name']),
            'placement_label' => $ad->placementLabel(),
            'is_embed' => $ad->isEmbed(),
            'image_url' => $ad->imageUrl(),
            'impressions' => $ad->impressions()->count(),
            'clicks' => $ad->clicks()->count(),
        ];
    }
}
