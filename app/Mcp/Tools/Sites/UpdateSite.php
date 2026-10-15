<?php

namespace App\Mcp\Tools\Sites;

use App\Mcp\Support\SiteFields;
use App\Mcp\Support\SiteLookup;
use App\Mcp\Tools\AdminTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use RuntimeException;

#[Description('Update any setting of a site. Only the fields you pass change; pass null to clear an optional field. Array fields (domains, tags, nav_links…) replace the stored list, except legal_pages which is merged per page.')]
#[IsIdempotent]
class UpdateSite extends AdminTool
{
    public function handle(Request $request): Response
    {
        $request->validate(['site' => ['required']]);

        $site = SiteLookup::find($request->get('site'));

        if ($site === null) {
            return Response::error("No site matches \"{$request->get('site')}\". Deleted sites must be restored before editing.");
        }

        $validated = Arr::except($request->validate([
            'site' => ['required'],
            ...SiteFields::rules($site),
        ]), ['site']);

        if ($validated === []) {
            return Response::error('Nothing to update — pass at least one field to change.');
        }

        try {
            $site->update(SiteFields::toAttributes($validated, $site));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json(SiteFields::present($site->refresh()));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('Site id or slug to update.')->required(),
            ...SiteFields::schema($schema),
        ];
    }
}
