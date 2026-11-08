<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;

/** Paket client dan pembayaran dikelola admin, dilihat director, dan dilihat tutor pembimbingnya. */
class EnrollmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Enrollment $enrollment): bool
    {
        return $user->isStaff()
            || ($user->isTutor() && $user->tutor?->id === $enrollment->primary_tutor_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Enrollment $enrollment): bool
    {
        return $user->isAdmin();
    }

    public function recordPayment(User $user, Enrollment $enrollment): bool
    {
        return $user->isAdmin();
    }
}
