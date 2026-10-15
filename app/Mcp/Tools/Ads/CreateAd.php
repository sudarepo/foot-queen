<?php

namespace App\Mcp\Tools\Ads;

use App\Mcp\Support\AdFields;
use App\Mcp\Tools\AdminTool;
use App\Models\Ad;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use RuntimeException;

#[Description('Create an ad: either an image banner (image_url + destination_url) or a third-party embed (embed_code).')]
class CreateAd extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(AdFields::rules());

        $ad = new Ad(['is_active' => true, 'weight' => 1]);

        $ad->fill(collect($validated)->except(['site', 'image_url'])->all());

        if (filled($validated['image_url'] ?? null)) {
            // Stand-in so the completeness check passes; the real path is
            // written below, once the download has succeeded.
            $ad->image_path = 'pending';
        }

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
        $fields = AdFields::schema($schema);

        foreach (['placement', 'advertiser', 'campaign', 'creative'] as $required) {
            $fields[$required]->required();
        }

        return $fields;
    }
}
