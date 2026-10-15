<?php

namespace App\Mcp\Tools\Operations;

use App\Mcp\Support\SiteLookup;
use App\Mcp\Tools\AdminTool;
use App\Services\Forge\ForgeDomainProvisioner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Which of a site\'s domains are missing from Laravel Forge, and which have no SSL certificate yet.')]
#[IsReadOnly]
class GetForgeStatus extends AdminTool
{
    public function handle(Request $request, ForgeDomainProvisioner $forge): Response
    {
        $request->validate(['site' => ['required']]);

        if (! $forge->isConfigured()) {
            return Response::error('Forge is not configured on this server (FORGE_API_TOKEN, FORGE_SERVER_ID, FORGE_SITE_ID).');
        }

        $site = SiteLookup::find($request->get('site'));

        if ($site === null) {
            return Response::error("No site matches \"{$request->get('site')}\".");
        }

        try {
            return Response::json([
                'site' => $site->only(['id', 'slug', 'name', 'domains']),
                ...$forge->siteStatus($site),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return Response::error("Forge could not be reached: {$exception->getMessage()}");
        }
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
