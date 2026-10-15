<?php

namespace App\Mcp\Tools\Sites;

use App\Mcp\Support\SiteFields;
use App\Mcp\Support\SiteLookup;
use App\Mcp\Tools\AdminTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get every setting of one site — identity, branding, layout, content, copy, legal overrides and tracking — plus its assigned users.')]
#[IsReadOnly]
class GetSite extends AdminTool
{
    public function handle(Request $request): Response
    {
        $request->validate(['site' => ['required']]);

        $site = SiteLookup::find($request->get('site'), withTrashed: true);

        if ($site === null) {
            return Response::error("No site matches \"{$request->get('site')}\".");
        }

        return Response::json([
            ...SiteFields::present($site),
            'users' => $site->users()->get(['users.id', 'name', 'email'])->map->only(['id', 'name', 'email'])->all(),
        ]);
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
