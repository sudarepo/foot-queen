<?php

namespace App\Mcp\Tools\Ads;

use App\Mcp\Support\AdFields;
use App\Mcp\Tools\AdminTool;
use App\Models\Ad;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use RuntimeException;

#[Description('Update an ad. Only the fields you pass change — e.g. {"ad_id": 3, "is_active": false} pauses it.')]
#[IsIdempotent]
class UpdateAd extends AdminTool
{
    public function handle(Request $request): Response
    {
        $request->validate(['ad_id' => ['required', 'integer']]);

        $ad = Ad::query()->find($request->get('ad_id'));

        if ($ad === null) {
            return Response::error("No ad with id {$request->get('ad_id')}.");
        }

        $validated = Arr::except($request->validate([
            'ad_id' => ['required', 'integer'],
            ...AdFields::rules($ad),
        ]), ['ad_id']);

        if ($validated === []) {
            return Response::error('Nothing to update — pass at least one field to change.');
        }

        if (filled($validated['image_url'] ?? null)) {
            // Stand-in so the completeness check passes; the real path is
            // written below, once the download has succeeded.
            $ad->image_path ??= 'pending';
        }

        $ad->fill(Arr::except($validated, ['site', 'image_url']));

        if (($error = AdFields::incompleteBannerError($ad)) !== null) {
            return Response::error($error);
        }

        try {
            $ad->fill(AdFields::toAttributes($validated))->save();
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json(AdFields::present($ad->refresh()));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ad_id' => $schema->integer()->required(),
            ...AdFields::schema($schema),
        ];
    }
}
