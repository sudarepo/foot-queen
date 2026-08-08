<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A panel user — either an admin, who sees the whole network, or someone
 * scoped to the sites assigned to them (see the `sites` relation and
 * App\Policies\SitePolicy).
 */
#[Fillable(['name', 'email', 'password', 'is_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * The sites this user administers. Empty for admins, who reach every site
     * without being listed against any of them.
     *
     * @return BelongsToMany<Site, $this>
     */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class);
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * The site ids this user may see data for, or null for "no limit" — the
     * distinction a `whereIn` can't express, since an admin isn't assigned
     * every site, they're simply unrestricted.
     *
     * @return array<int, int>|null
     */
    public function administeredSiteIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        return $this->sites()->pluck('sites.id')->all();
    }

    /**
     * Whether this user may manage a given site — an admin always may, anyone
     * else only if the site is assigned to them.
     */
    public function administers(Site $site): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->sites()->whereKey($site->getKey())->exists();
    }

    /**
     * There's no public registration route, so a user row only exists because
     * someone deliberately created it. It still has to be *for* something:
     * an account that is neither an admin nor assigned a single site has
     * nothing to manage, and letting it into the panel would show it an empty
     * shell — so access is revoked with the last site assignment rather than
     * outliving it.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin() || $this->sites()->exists();
    }
}
