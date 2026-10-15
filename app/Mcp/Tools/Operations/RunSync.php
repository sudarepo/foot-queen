<?php

namespace App\Mcp\Tools\Operations;

use App\Mcp\Tools\AdminTool;
use App\Services\CamProfileService;
use App\Services\CamSyncService;
use App\Services\ChaturbateAffiliateStatsSyncService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Throwable;

#[Description('Run a background sync now instead of waiting for the schedule: "cams" (live rooms, every 5 min), "cam-profiles" (bios and photos, ~3s per performer) or "chaturbate-stats" (affiliate revenue, hourly).')]
#[IsIdempotent]
#[IsOpenWorld]
class RunSync extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'task' => ['required', 'in:cams,cam-profiles,chaturbate-stats'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:40'],
        ]);

        try {
            $result = match ($validated['task']) {
                'cams' => ['cams_per_provider' => app(CamSyncService::class)->syncAll()],
                'cam-profiles' => app(CamProfileService::class)->refreshStale($validated['limit'] ?? 10),
                'chaturbate-stats' => ['rows_synced' => app(ChaturbateAffiliateStatsSyncService::class)->sync()],
            };
        } catch (Throwable $exception) {
            report($exception);

            return Response::error("The {$validated['task']} sync failed: {$exception->getMessage()}");
        }

        return Response::json(['task' => $validated['task'], ...$result]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->enum(['cams', 'cam-profiles', 'chaturbate-stats'])->required(),
            'limit' => $schema->integer()->default(10)->description('cam-profiles only: performers to fetch (max 40). Each takes ~3s, so keep it small to stay within client timeouts.'),
        ];
    }
}
