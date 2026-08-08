<?php

namespace App\Policies;

use App\Models\User;

/**
 * Accounts are admin-only territory: the form grants both the admin flag and
 * site assignments, so anyone who can edit users can grant themselves the
 * whole network.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $subject): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $subject): bool
    {
        return $user->isAdmin();
    }

    /**
     * Deleting yourself would log you out mid-session, and if you were the
     * last admin it would leave the panel with no one able to create another.
     */
    public function delete(User $user, User $subject): bool
    {
        return $user->isAdmin() && ! $user->is($subject);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
