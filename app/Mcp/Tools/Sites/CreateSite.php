<?php

namespace App\Mcp\Tools\Sites;

use App\Mcp\Support\SiteFields;
use App\Mcp\Tools\AdminTool;
use App\Models\Site;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use RuntimeException;

#[Description('Create a new site (domain) in the network. Only "name" is required; everything else falls back to sensible defaults. Remember the domain also needs DNS and Forge (see sync-forge-domains).')]
class CreateSite extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(SiteFields::rules());

        try {
            $attributes = SiteFields::toAttributes($validated);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }

        $site = Site::query()->create([
            'is_active' => true,
            ...$attributes,
        ]);

        return Response::json(SiteFields::present($site->refresh()));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $fields = SiteFields::schema($schema);
        $fields['name']->required();

        return $fields;
    }
}
