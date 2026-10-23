<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Which cams each site's tags match, worked out ahead of time.
 *
 * Cam::scopeForSite used to answer that at read time with one JSON_CONTAINS
 * per site tag — ~30 of them on average, none indexable — on every listing,
 * header count and sitemap, for every one of ~240 domains. Under crawler
 * traffic that pinned MySQL and starved the whole box, admin included.
 *
 * Tags only change when the sync sees a cam or someone edits a site, so the
 * match is computed then and stored in `cam_site`; reads become an indexed
 * join. Gender is not part of it — that's a plain indexed column the scope
 * still filters on — and sites with no tags have no rows at all, since they
 * aren't narrowed by tag.
 */
class SiteCamIndex
{
    private const INSERT_CHUNK = 1000;

    /**
     * Re-match a batch of cams against every tagged site, writing only the
     * difference — a cam's tags rarely change between syncs, so most calls
     * touch nothing.
     *
     * @param  array<int, array<int, string>|string|null>  $tagsByCamId  Cam id => its tags (array or JSON string).
     */
    public function indexCams(array $tagsByCamId): void
    {
        if ($tagsByCamId === []) {
            return;
        }

        $sites = $this->taggedSites();
        $wanted = [];

        foreach ($tagsByCamId as $camId => $tags) {
            foreach ($this->matchingSiteIds($this->decodeTags($tags), $sites) as $siteId) {
                $wanted["{$siteId}:{$camId}"] = ['site_id' => $siteId, 'cam_id' => $camId];
            }
        }

        $existing = [];

        foreach (array_chunk(array_keys($tagsByCamId), self::INSERT_CHUNK) as $camIds) {
            DB::table('cam_site')->whereIn('cam_id', $camIds)->get(['site_id', 'cam_id'])
                ->each(function (object $row) use (&$existing) {
                    $existing["{$row->site_id}:{$row->cam_id}"] = ['site_id' => $row->site_id, 'cam_id' => $row->cam_id];
                });
        }

        foreach (array_chunk(array_values(array_diff_key($wanted, $existing)), self::INSERT_CHUNK) as $rows) {
            DB::table('cam_site')->insertOrIgnore($rows);
        }

        collect(array_diff_key($existing, $wanted))
            ->groupBy('site_id')
            ->each(fn ($rows, $siteId) => DB::table('cam_site')
                ->where('site_id', $siteId)
                ->whereIn('cam_id', $rows->pluck('cam_id'))
                ->delete());
    }

    /**
     * Rebuild one site's rows from scratch, after its tags change or it is
     * created or restored.
     */
    public function rebuildSite(Site $site): void
    {
        DB::table('cam_site')->where('site_id', $site->getKey())->delete();

        $tags = $site->trashed() ? [] : ($site->tags ?? []);

        if ($tags === []) {
            return;
        }

        $this->scan([$site->getKey() => array_flip($tags)]);
    }

    /**
     * Rebuild every site's rows in one pass over the cams table.
     */
    public function rebuild(): void
    {
        DB::table('cam_site')->delete();

        $this->scan($this->taggedSites());
    }

    /**
     * @param  array<int, array<string, int>>  $sites  Site id => its tags as a set.
     */
    private function scan(array $sites): void
    {
        if ($sites === []) {
            return;
        }

        $rows = [];

        foreach (DB::table('cams')->select(['id', 'tags'])->lazyById(5000) as $cam) {
            foreach ($this->matchingSiteIds($this->decodeTags($cam->tags), $sites) as $siteId) {
                $rows[] = ['site_id' => $siteId, 'cam_id' => $cam->id];
            }

            if (count($rows) >= self::INSERT_CHUNK) {
                DB::table('cam_site')->insertOrIgnore($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('cam_site')->insertOrIgnore($rows);
        }
    }

    /**
     * Every live (non-deleted) site that narrows by tag. Inactive sites are
     * included so switching one back on needs no rebuild.
     *
     * @return array<int, array<string, int>> Site id => its tags as a set.
     */
    private function taggedSites(): array
    {
        return Site::query()->get(['id', 'tags'])
            ->filter(fn (Site $site) => filled($site->tags))
            ->mapWithKeys(fn (Site $site) => [$site->getKey() => array_flip($site->tags)])
            ->all();
    }

    /**
     * Exact string match, as JSON_CONTAINS did — both sides are already in
     * Chaturbate's lowercase form (see Site::normalizeTag and the provider).
     *
     * @param  array<int, string>  $camTags
     * @param  array<int, array<string, int>>  $sites
     * @return array<int, int>
     */
    private function matchingSiteIds(array $camTags, array $sites): array
    {
        $matches = [];

        foreach ($sites as $siteId => $siteTags) {
            foreach ($camTags as $tag) {
                if (isset($siteTags[$tag])) {
                    $matches[] = $siteId;
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * @param  array<int, string>|string|null  $tags
     * @return array<int, string>
     */
    private function decodeTags(array|string|null $tags): array
    {
        if (is_string($tags)) {
            $tags = json_decode($tags, true);
        }

        return is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];
    }
}
