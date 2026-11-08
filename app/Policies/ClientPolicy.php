<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

/**
 * Admin mengelola client. Director melihat semua. Tutor hanya melihat client yang ia bimbing
 * (daftar client untuk tutor difilter dengan scope Client::handledByTutor).
 */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Client $client): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        $tutor = $user->tutor;

        return $tutor !== null && Client::query()->whereKey($client->id)->handledByTutor($tutor)->exists();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Client $client): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->isAdmin();
    }
}
