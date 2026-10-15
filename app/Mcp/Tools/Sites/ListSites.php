<?php

namespace App\Mcp\Tools\Sites;

use App\Mcp\Tools\AdminTool;
use App\Models\Site;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the sites (domains) in the network with their key settings. Use get-site for every field of one site.')]
#[IsReadOnly]
class ListSites extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,inactive,deleted,all'],
        ]);

        $search = $validated['search'] ?? null;

        $sites = Site::query()
            ->when(($validated['status'] ?? 'all') === 'all', fn ($query) => $query->withTrashed())
            ->when(($validated['status'] ?? null) === 'deleted', fn ($query) => $query->onlyTrashed())
            ->when(($validated['status'] ?? null) === 'active', fn ($query) => $query->where('is_active', true))
            ->when(($validated['status'] ?? null) === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->orWhere('domains', 'like', "%{$search}%")))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return Response::json($sites->map(fn (Site $site): array => [
            'id' => $site->id,
            'slug' => $site->slug,
            'name' => $site->name,
            'domains' => $site->domains ?? [],
            'is_active' => $site->is_active,
            'is_default' => $site->is_default,
            'is_deleted' => $site->trashed(),
            'gender' => $site->gender,
            'tags' => $site->tags ?? [],
            'home_layout_desktop' => $site->homeLayout('desktop')->value,
            'home_layout_mobile' => $site->homeLayout('mobile')->value,
            'track_prefix' => $site->track_prefix,
        ])->all());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Match against name, slug or domain.'),
            'status' => $schema->string()->enum(['active', 'inactive', 'deleted', 'all'])->default('all')->description('Filter by state. "deleted" lists soft-deleted sites that can be restored.'),
        ];
    }
}
