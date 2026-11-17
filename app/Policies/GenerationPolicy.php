<?php

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;

class GenerationPolicy
{
    public function view(User $user, Generation $generation): bool
    {
        return $generation->user_id === $user->id;
    }

    public function update(User $user, Generation $generation): bool
    {
        return $generation->user_id === $user->id;
    }

    public function delete(User $user, Generation $generation): bool
    {
        return $generation->user_id === $user->id && ! $generation->status->isActive();
    }
}
