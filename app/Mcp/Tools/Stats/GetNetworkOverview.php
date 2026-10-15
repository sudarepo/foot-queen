<?php

namespace App\Mcp\Tools\Stats;

use App\Mcp\Tools\AdminTool;
use App\Models\Ad;
use App\Models\Cam;
use App\Models\CamClickEvent;
use App\Models\ChaturbateStatsDay;
use App\Models\PageViewEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\Forge\ForgeDomainProvisioner;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('A one-call snapshot of the whole network: sites, cams, ads, users, the last 24h of traffic, and when each background sync last ran. A good first call.')]
#[IsReadOnly]
class GetNetworkOverview extends AdminTool
{
    public function handle(Request $request, ForgeDomainProvisioner $forge): Response
    {
        $since = now()->subDay();

        return Response::json([
            'generated_at' => now()->toIso8601String(),
            'sites' => [
                'active' => Site::query()->where('is_active', true)->count(),
                'inactive' => Site::query()->where('is_active', false)->count(),
                'deleted' => Site::onlyTrashed()->count(),
                'default' => Site::query()->where('is_default', true)->value('slug'),
            ],
            'cams' => [
                'total' => Cam::query()->count(),
                'online' => Cam::query()->online()->count(),
                'last_synced_at' => Cam::query()->max('updated_at'),
                'profiles_fetched' => Cam::query()->whereNotNull('profile_fetched_at')->count(),
                'last_profile_fetched_at' => Cam::query()->max('profile_fetched_at'),
            ],
            'ads' => [
                'total' => Ad::query()->count(),
                'active' => Ad::query()->where('is_active', true)->count(),
            ],
            'users' => [
                'admins' => User::query()->where('is_admin', true)->count(),
                'site_managers' => User::query()->where('is_admin', false)->count(),
            ],
            'last_24h' => [
                'pageviews' => PageViewEvent::query()->where('created_at', '>=', $since)->count(),
                'outbound_clicks' => CamClickEvent::query()->where('created_at', '>=', $since)->count(),
            ],
            'chaturbate_stats_last_synced_at' => ChaturbateStatsDay::query()->max('updated_at'),
            'forge_configured' => $forge->isConfigured(),
        ]);
    }
}
