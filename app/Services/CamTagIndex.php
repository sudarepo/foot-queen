<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Each cam's tags as rows (`cam_tags`), so Cam::scopeForSite can match a
 * site's tags with an index instead of JSON.
 *
 * The scope used to do one JSON_CONTAINS per site tag — ~30 on average, none
 * indexable — on every listing, header count and sitemap, for every one of
 * ~240 domains. Under crawler traffic that pinned MySQL and starved the whole
 * box, admin included.
 *
 * Indexed per tag rather than per site: sites share generic tags ('new',
 * 'xxx', '18'), so the average cam matches ~150 of them and a site ↔ cam table
 * ran to tens of millions of rows. Cams carry ~4 tags each, and a site's tag
 * edit needs no re-index at all.
 */
class CamTagIndex
{
    private const INSERT_CHUNK = 1000;

    /**
     * The longest tag kept — Chaturbate's are single short words, so anything
     * past this is junk rather than a niche.
     */
    public const MAX_TAG_LENGTH = 100;

    /**
     * Bring a batch of cams' rows in line with their current tags, writing
     * only the difference — tags rarely change between syncs, so most calls
     * touch nothing.
     *
     * @param  array<int, array<int, string>|string|null>  $tagsByCamId  Cam id => its tags (array or JSON string).
     */
    public function indexCams(array $tagsByCamId): void
    {
        if ($tagsByCamId === []) {
            return;
        }

        $wanted = [];

        foreach ($tagsByCamId as $camId => $tags) {
            foreach ($this->decodeTags($tags) as $tag) {
                $wanted["{$camId}:{$tag}"] = ['cam_id' => $camId, 'tag' => $tag];
            }
        }

        $existing = [];

        foreach (array_chunk(array_keys($tagsByCamId), self::INSERT_CHUNK) as $camIds) {
            DB::table('cam_tags')->whereIn('cam_id', $camIds)->get(['cam_id', 'tag'])
                ->each(function (object $row) use (&$existing) {
                    $existing["{$row->cam_id}:{$row->tag}"] = ['cam_id' => $row->cam_id, 'tag' => $row->tag];
                });
        }

        foreach (array_chunk(array_values(array_diff_key($wanted, $existing)), self::INSERT_CHUNK) as $rows) {
            DB::table('cam_tags')->insertOrIgnore($rows);
        }

        collect(array_diff_key($existing, $wanted))
            ->groupBy('cam_id')
            ->each(fn ($rows, $camId) => DB::table('cam_tags')
                ->where('cam_id', $camId)
                ->whereIn('tag', $rows->pluck('tag'))
                ->delete());
    }

    /**
     * Rebuild every row from the cams table. One INSERT … SELECT on MySQL
     * (seconds for the whole pool); a PHP pass elsewhere, i.e. the SQLite
     * test database.
     */
    public function rebuild(): void
    {
        DB::table('cam_tags')->delete();

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'INSERT IGNORE INTO cam_tags (cam_id, tag)
                 SELECT cams.id, jt.tag
                 FROM cams, JSON_TABLE(cams.tags, \'$[*]\' COLUMNS (tag VARCHAR(255) PATH \'$\')) AS jt
                 WHERE jt.tag IS NOT NULL AND jt.tag <> \'\' AND CHAR_LENGTH(jt.tag) <= ?',
                [self::MAX_TAG_LENGTH]
            );

            return;
        }

        $rows = [];

        foreach (DB::table('cams')->select(['id', 'tags'])->lazyById(5000) as $cam) {
            foreach ($this->decodeTags($cam->tags) as $tag) {
                $rows[] = ['cam_id' => $cam->id, 'tag' => $tag];
            }

            if (count($rows) >= self::INSERT_CHUNK) {
                DB::table('cam_tags')->insertOrIgnore($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('cam_tags')->insertOrIgnore($rows);
        }
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

        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $tags,
            fn (mixed $tag) => is_string($tag) && $tag !== '' && mb_strlen($tag) <= self::MAX_TAG_LENGTH
        )));
    }
}
