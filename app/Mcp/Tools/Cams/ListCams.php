<?php

namespace App\Mcp\Tools\Cams;

use App\Mcp\Support\SiteLookup;
use App\Mcp\Tools\AdminTool;
use App\Models\Cam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Search the synced cam pool. Pass a site to see exactly what that domain shows (its gender + tag filter). Cams are read-only — they are overwritten by the provider sync.')]
#[IsReadOnly]
class ListCams extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'site' => ['nullable'],
            'search' => ['nullable', 'string', 'max:120'],
            'online_only' => ['nullable', 'boolean'],
            'category' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $site = SiteLookup::find($validated['site'] ?? null);

        if (filled($validated['site'] ?? null) && $site === null) {
            return Response::error("No site matches \"{$validated['site']}\".");
        }

        $query = Cam::query()
            ->when($site, fn ($query) => $query->forSite($site))
            ->when($validated['online_only'] ?? true, fn ($query) => $query->online())
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where('username', 'like', "%{$search}%"))
            ->when($validated['category'] ?? null, fn ($query, string $category) => $query->whereJsonContains('categories', $category));

        $total = (clone $query)->count();

        $cams = $query->orderByDesc('viewers')->limit($validated['limit'] ?? 50)->get();

        return Response::json([
            'total_matching' => $total,
            'cams' => $cams->map(fn (Cam $cam): array => [
                'id' => $cam->id,
                'username' => $cam->username,
                'provider' => $cam->provider,
                'is_online' => $cam->is_online,
                'viewers' => $cam->viewers,
                'gender' => $cam->gender,
                'age' => $cam->age,
                'country' => $cam->country,
                'tags' => $cam->tags ?? [],
                'is_hd' => $cam->is_hd,
                'last_seen_at' => $cam->last_seen_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('Site id or slug to scope to.'),
            'search' => $schema->string()->description('Username contains.'),
            'online_only' => $schema->boolean()->default(true),
            'category' => $schema->string(),
            'limit' => $schema->integer()->default(50)->description('Max 200, ordered by viewers.'),
        ];
    }
}
