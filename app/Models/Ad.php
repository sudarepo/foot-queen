<?php

namespace App\Models;

use App\Services\AdPlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ad extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'weight' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function impressions(): HasMany
    {
        return $this->hasMany(AdImpression::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AdClick::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActiveForPlacement(Builder $query, AdPlacement $placement, Site $site): void
    {
        $now = now();

        $query
            ->where('is_active', true)
            ->where('placement', $placement->value)
            ->where(function (Builder $scope) use ($site): void {
                $scope->whereNull('site_id')->orWhere('site_id', $site->getKey());
            })
            ->where(function (Builder $scope) use ($now): void {
                $scope->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $scope) use ($now): void {
                $scope->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }

    public function placementLabel(): string
    {
        return AdPlacement::from($this->placement)->label();
    }

    public function imageUrl(): ?string
    {
        return filled($this->image_path)
            ? asset('storage/'.ltrim($this->image_path, '/'))
            : null;
    }
}
