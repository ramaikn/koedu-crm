<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WaConversation;

/** Inbox WA milik tim admin. Director boleh membaca untuk pemantauan, tapi tidak membalas. */
class WaConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, WaConversation $conversation): bool
    {
        return $user->isStaff();
    }

    public function reply(User $user, WaConversation $conversation): bool
    {
        return $user->isAdmin();
    }
}
