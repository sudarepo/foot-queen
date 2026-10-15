<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Ads\CreateAd;
use App\Mcp\Tools\Ads\DeleteAd;
use App\Mcp\Tools\Ads\GetAd;
use App\Mcp\Tools\Ads\ListAds;
use App\Mcp\Tools\Ads\UpdateAd;
use App\Mcp\Tools\Cams\GetCam;
use App\Mcp\Tools\Cams\ListCams;
use App\Mcp\Tools\Operations\GetForgeStatus;
use App\Mcp\Tools\Operations\RunSync;
use App\Mcp\Tools\Operations\SyncForgeDomains;
use App\Mcp\Tools\Sites\CreateSite;
use App\Mcp\Tools\Sites\DeleteSite;
use App\Mcp\Tools\Sites\GetSite;
use App\Mcp\Tools\Sites\ListSites;
use App\Mcp\Tools\Sites\RestoreSite;
use App\Mcp\Tools\Sites\UpdateSite;
use App\Mcp\Tools\Stats\GetAdStats;
use App\Mcp\Tools\Stats\GetChaturbateRevenue;
use App\Mcp\Tools\Stats\GetNetworkOverview;
use App\Mcp\Tools\Stats\GetTrafficStats;
use App\Mcp\Tools\Users\CreateUser;
use App\Mcp\Tools\Users\DeleteUser;
use App\Mcp\Tools\Users\ListUsers;
use App\Mcp\Tools\Users\UpdateUser;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Admin access to the whole network for AI clients. Mounted at /mcp/admin
 * behind AuthenticateMcpToken — see routes/ai.php and MCP.md.
 */
#[Name('Foot Queen Admin')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
    Administers a network of live-cam affiliate websites that share one database and one pool of cams synced from Chaturbate.

    - A "site" is one domain: its branding, copy, the gender + tags that decide which cams it shows, homepage layout (grid/feed A/B test), legal page overrides and tracking IDs. Refer to sites by id or slug.
    - Start with get-network-overview for a snapshot, then list-*/get-* tools for detail.
    - Update tools change only the fields you pass. List fields (domains, tags, featured_categories, nav_links, site_ids) replace the stored list, so to add or remove one item, read the current list first (get-site / list-users) and send the full new list. Prefer update-site over delete-site; deletes of sites are soft and reversible with restore-site, but delete-ad and delete-user are permanent.
    - Revenue (get-chaturbate-revenue) is network-wide only; per-site performance is pageviews/clicks/CTR from get-traffic-stats.
    - sync-forge-domains is a dry run unless dry_run=false. Confirm with the user before applying it or any destructive action.
    - Landing-page registries (config/seo-pages) and the cam taxonomy are code, not data, and need a deploy to change.
    TEXT)]
class AdminServer extends Server
{
    /**
     * Every tool in one tools/list page — not every client follows the
     * cursor, and one that doesn't would silently never see the rest.
     */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        GetNetworkOverview::class,

        ListSites::class,
        GetSite::class,
        CreateSite::class,
        UpdateSite::class,
        DeleteSite::class,
        RestoreSite::class,

        ListAds::class,
        GetAd::class,
        CreateAd::class,
        UpdateAd::class,
        DeleteAd::class,

        ListUsers::class,
        CreateUser::class,
        UpdateUser::class,
        DeleteUser::class,

        ListCams::class,
        GetCam::class,

        GetTrafficStats::class,
        GetAdStats::class,
        GetChaturbateRevenue::class,

        GetForgeStatus::class,
        SyncForgeDomains::class,
        RunSync::class,
    ];
}
