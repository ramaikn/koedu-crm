<?php

namespace App\Policies;

use App\Models\Tutor;
use App\Models\User;

/**
 * Profil tutor (bidang, jam tersedia, batas sesi) diatur admin dan director.
 * Tutor boleh mengubah jam tersedianya sendiri. Akun login tutor tetap diatur lewat UserPolicy.
 */
class TutorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Tutor $tutor): bool
    {
        return $user->isStaff() || $user->tutor?->id === $tutor->id;
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Tutor $tutor): bool
    {
        return $user->isStaff();
    }

    public function updateAvailability(User $user, Tutor $tutor): bool
    {
        return $user->isStaff() || $user->tutor?->id === $tutor->id;
    }
}
