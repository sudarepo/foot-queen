<?php

namespace App\Mcp\Tools\Sites;

use App\Mcp\Support\SiteLookup;
use App\Mcp\Tools\AdminTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Restore a soft-deleted site.')]
#[IsIdempotent]
class RestoreSite extends AdminTool
{
    public function handle(Request $request): Response
    {
        $request->validate(['site' => ['required']]);

        $site = SiteLookup::find($request->get('site'), withTrashed: true);

        if ($site === null) {
            return Response::error("No site matches \"{$request->get('site')}\".");
        }

        if (! $site->trashed()) {
            return Response::text("Site \"{$site->name}\" is not deleted — nothing to restore.");
        }

        $site->restore();

        return Response::text("Site \"{$site->name}\" (id {$site->id}) restored.");
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('Site id or slug.')->required(),
        ];
    }
}
