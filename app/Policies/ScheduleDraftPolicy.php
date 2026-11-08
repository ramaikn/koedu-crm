<?php

namespace App\Policies;

use App\Models\ScheduleDraft;
use App\Models\User;

/** Draf jadwal dari AI hanya boleh disetujui atau ditolak oleh admin. */
class ScheduleDraftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function review(User $user, ScheduleDraft $draft): bool
    {
        return $user->isAdmin();
    }
}
