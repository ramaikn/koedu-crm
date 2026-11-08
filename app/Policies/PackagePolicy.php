<?php

namespace App\Policies;

use App\Models\Package;
use App\Models\User;

/** Katalog paket dan harga adalah keputusan bisnis, jadi diubah oleh director. Admin hanya melihat. */
class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Package $package): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isDirector();
    }

    public function update(User $user, Package $package): bool
    {
        return $user->isDirector();
    }
}
