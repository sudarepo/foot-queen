<?php

namespace App\Mcp\Tools\Ads;

use App\Mcp\Tools\AdminTool;
use App\Models\Ad;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Permanently delete an ad, along with its impression and click history. To stop it serving but keep its stats, use update-ad with is_active=false instead.')]
#[IsDestructive]
class DeleteAd extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['ad_id' => ['required', 'integer']]);

        $ad = Ad::query()->find($validated['ad_id']);

        if ($ad === null) {
            return Response::error("No ad with id {$validated['ad_id']}.");
        }

        $ad->delete();

        return Response::text("Ad {$ad->id} ({$ad->advertiser} / {$ad->campaign} / {$ad->creative}) deleted.");
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ad_id' => $schema->integer()->required(),
        ];
    }
}
