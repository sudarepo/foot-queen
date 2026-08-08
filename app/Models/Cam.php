<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'is_hd' => 'boolean',
        'is_new' => 'boolean',
        'last_seen_at' => 'datetime',
        'profile_fetched_at' => 'datetime',
    ];

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
     */
    public function scopeForSite($query, Site $site)
    {
        if (filled($site->gender)) {
            $query->where('gender', $site->gender);
        }

        $tags = $site->tags ?? [];

        // No tags means the site is defined by gender alone (or takes
        // everything) — a niche-less site, not a site with an empty niche.
        if ($tags !== []) {
            $query->where(function ($query) use ($tags) {
                foreach ($tags as $tag) {
                    $query->orWhereJsonContains('tags', $tag);
                }
            });
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
