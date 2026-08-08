<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

/**
 * Creating, deleting, and renaming domains is network-level work; editing a
 * site's branding and copy is what an assigned user is here to do. Filament
 * reads this policy automatically for SiteResource's pages and actions.
 */
class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Site $site): bool
    {
        return $user->administers($site);
    }

    /**
     * A new site is a new domain in the network — and, since the form can mark
     * it default, a way to hijack the fallback host. Admins only.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Site $site): bool
    {
        return $user->administers($site);
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
