<?php

namespace App\Models;

use App\Services\CamTagIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Cam extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'categories' => 'array',
        'tags' => 'array',
        'profile_attributes' => 'array',
        'photo_sets' => 'array',
        'is_online' => 'boolean',
        'is_banned' => 'boolean',
        'is_hd' => 'boolean',
        'is_new' => 'boolean',
        'last_seen_at' => 'datetime',
        'profile_fetched_at' => 'datetime',
    ];

    /**
     * How long the per-site "N live" figure is reused. It's on every page of
     * every domain, and nobody can tell 7,204 from 7,211.
     */
    private const ONLINE_COUNT_TTL = 60;

    protected static function booted(): void
    {
        // A banned cam remains in the pool for admin visibility and sync,
        // but is hidden from all user-facing query paths by default.
        static::addGlobalScope('not_banned', function (Builder $builder) {
            $builder->where('is_banned', false);
        });

        /**
         * Keeps cam_tags current for cams written through the model. The
         * sync upserts through the query builder and indexes its batches
         * itself (CamSyncService).
         */
        static::saved(function (Cam $cam) {
            if ($cam->wasRecentlyCreated || $cam->wasChanged('tags')) {
                app(CamTagIndex::class)->indexCams([$cam->getKey() => $cam->tags]);
            }
        });
    }

    /**
     * This site's online performer count, cached briefly.
     */
    public static function onlineCountFor(Site $site): int
    {
        return Cache::remember(
            "sites.{$site->getKey()}.online-count",
            self::ONLINE_COUNT_TTL,
            fn () => static::online()->forSite($site)->count(),
        );
    }

    /* ----------  Profile accessors  ---------- */

    /**
     * A single scalar from the fetched profile, e.g. `location`, `languages`.
     */
    public function profileAttribute(string $key): mixed
    {
        return $this->profile_attributes[$key] ?? null;
    }

    /**
     * Photo/video sets, videos first — a video set is the stronger hook, and
     * performers rarely order their own tab deliberately.
     *
     * @return array<int, array<string, mixed>>
     */
    public function orderedPhotoSets(): array
    {
        $sets = $this->photo_sets ?? [];

        usort($sets, fn ($a, $b) => ($b['is_video'] ?? false) <=> ($a['is_video'] ?? false));

        return $sets;
    }

    /* ----------  Query scopes  ---------- */

    public function scopeOnline($query)
    {
        return $query->where('is_online', true);
    }

    /**
     * Narrows the shared pool to one site's slice of it.
     *
     * The cams table holds the union of every active site's niche — one sync
     * run fetches all of them, and a performer tagged both 'feet' and 'bbw'
     * is stored once and shown on both sites. This scope is what keeps those
     * sites looking separate, so it has to be on *every* read path that
     * reaches a visitor: listings, the online count in the header, and the
     * sitemap. Miss one and a domain advertises another domain's performers.
     *
     * Deliberately not clearable from the UI, unlike the category filter — a
     * visitor picking "All categories" is asking to leave the site's default
     * category, not to leave the site.
     *
     * Tags are matched through the indexed cam_tags rows (see CamTagIndex) —
     * matching JSON at read time across ~240 domains is what used to saturate
     * MySQL.
     */
    public function scopeForSite($query, Site $site)
    {
        if (filled($site->gender)) {
            $query->where('gender', $site->gender);
        }

        // No tags means the site is defined by gender alone (or takes
        // everything) — a niche-less site, not a site with an empty niche.
        if (filled($site->tags)) {
            $query->whereIn(
                $query->qualifyColumn('id'),
                fn ($matches) => $matches->select('cam_id')->from('cam_tags')->whereIn('tag', $site->tags)
            );
        }

        return $query;
    }

    public function scopeFilter($query, array $filters)
    {
        if (! empty($filters['gender'])) {
            $query->where('gender', $filters['gender']);
        }

        if (! empty($filters['hair_color'])) {
            $query->where('hair_color', $filters['hair_color']);
        }

        if (! empty($filters['body_type'])) {
            $query->where('body_type', $filters['body_type']);
        }

        if (! empty($filters['age_range'])) {
            [$min, $max] = $this->ageRangeBounds($filters['age_range']);
            $query->whereBetween('age', [$min, $max]);
        }

        if (! empty($filters['category'])) {
            // JSON contains — MySQL 5.7+ / MariaDB 10.2+ / SQLite with JSON1
            $query->whereJsonContains('categories', $filters['category']);
        }

        if (! empty($filters['hd'])) {
            $query->where('is_hd', true);
        }

        return $query;
    }

    private function ageRangeBounds(string $range): array
    {
        return match ($range) {
            '18-22' => [18, 22],
            '23-29' => [23, 29],
            '30-39' => [30, 39],
            '40-49' => [40, 49],
            '50+' => [50, 120],
            default => [18, 120],
        };
    }
}
