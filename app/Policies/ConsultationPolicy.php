<?php

namespace App\Policies;

use App\Models\Consultation;
use App\Models\User;

/** Admin mengatur jadwal. Tutor hanya melihat dan menyelesaikan sesinya sendiri. */
class ConsultationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Consultation $consultation): bool
    {
        return $user->isStaff() || $this->isOwnTutor($user, $consultation);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Consultation $consultation): bool
    {
        return $user->isAdmin();
    }

    public function cancel(User $user, Consultation $consultation): bool
    {
        return $user->isAdmin();
    }

    /** Menandai selesai, mengisi absensi, dan catatan sesi. */
    public function complete(User $user, Consultation $consultation): bool
    {
        return $user->isAdmin() || $this->isOwnTutor($user, $consultation);
    }

    private function isOwnTutor(User $user, Consultation $consultation): bool
    {
        return $user->isTutor() && $user->tutor?->id === $consultation->tutor_id;
    }
}
