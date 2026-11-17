<?php

namespace App\Policies;

use App\Models\GenerationBatch;
use App\Models\User;

class GenerationBatchPolicy
{
    public function view(User $user, GenerationBatch $model): bool
    {
        return $model->user_id === $user->id;
    }

    public function update(User $user, GenerationBatch $model): bool
    {
        return $model->user_id === $user->id;
    }

    public function delete(User $user, GenerationBatch $model): bool
    {
        return $model->user_id === $user->id;
    }
}
