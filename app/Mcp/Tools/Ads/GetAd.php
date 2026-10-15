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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get every field of one ad, including its embed code and lifetime impression/click counts.')]
#[IsReadOnly]
class GetAd extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['ad_id' => ['required', 'integer']]);

        $ad = Ad::query()->find($validated['ad_id']);

        return $ad === null
            ? Response::error("No ad with id {$validated['ad_id']}.")
            : Response::json(AdFields::present($ad));
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
