<?php

namespace App\Mcp\Tools\Sites;

use App\Mcp\Support\SiteLookup;
use App\Mcp\Tools\AdminTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Soft-delete a site: it stops resolving immediately but keeps its data and can be brought back with restore-site.')]
#[IsDestructive]
class DeleteSite extends AdminTool
{
    public function handle(Request $request): Response
    {
        $request->validate(['site' => ['required']]);

        $site = SiteLookup::find($request->get('site'));

        if ($site === null) {
            return Response::error("No site matches \"{$request->get('site')}\".");
        }

        $site->delete();

        return Response::text("Site \"{$site->name}\" (id {$site->id}) deleted. Use restore-site to undo.");
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
