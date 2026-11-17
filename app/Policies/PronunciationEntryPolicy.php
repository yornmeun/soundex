<?php

namespace App\Policies;

use App\Models\PronunciationEntry;
use App\Models\User;

class PronunciationEntryPolicy
{
    public function view(User $user, PronunciationEntry $model): bool
    {
        return $model->user_id === $user->id;
    }

    public function update(User $user, PronunciationEntry $model): bool
    {
        return $model->user_id === $user->id;
    }

    public function delete(User $user, PronunciationEntry $model): bool
    {
        return $model->user_id === $user->id;
    }
}
