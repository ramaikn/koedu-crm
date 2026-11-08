<?php

namespace App\Policies;

use App\Models\User;

/** Hanya director yang mengelola akun pengguna dan role. */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isDirector();
    }

    public function create(User $user): bool
    {
        return $user->isDirector();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isDirector();
    }

    /** Director tidak bisa menonaktifkan akunnya sendiri, supaya tidak terkunci. */
    public function deactivate(User $user, User $target): bool
    {
        return $user->isDirector() && $user->isNot($target);
    }
}
