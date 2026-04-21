<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cam extends Model
{
    protected $guarded = [];

    protected $casts = [
        'categories'   => 'array',
        'is_online'    => 'boolean',
        'is_hd'        => 'boolean',
        'is_new'       => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    /* ----------  Query scopes  ---------- */

    public function scopeOnline($query)
    {
        return $query->where('is_online', true);
    }

    public function scopeFilter($query, array $filters)
    {
        if (!empty($filters['gender'])) {
            $query->where('gender', $filters['gender']);
        }

        if (!empty($filters['hair_color'])) {
            $query->where('hair_color', $filters['hair_color']);
        }

        if (!empty($filters['body_type'])) {
            $query->where('body_type', $filters['body_type']);
        }

        if (!empty($filters['age_range'])) {
            [$min, $max] = $this->ageRangeBounds($filters['age_range']);
            $query->whereBetween('age', [$min, $max]);
        }

        if (!empty($filters['category'])) {
            // JSON contains — MySQL 5.7+ / MariaDB 10.2+ / SQLite with JSON1
            $query->whereJsonContains('categories', $filters['category']);
        }

        if (!empty($filters['hd'])) {
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
            '50+'   => [50, 120],
            default => [18, 120],
        };
    }
}
