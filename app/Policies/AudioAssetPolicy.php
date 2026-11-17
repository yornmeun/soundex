<?php

namespace App\Policies;

use App\Models\AudioAsset;
use App\Models\User;

class AudioAssetPolicy
{
    public function view(User $user, AudioAsset $model): bool
    {
        return $model->user_id === $user->id;
    }

    public function update(User $user, AudioAsset $model): bool
    {
        return $model->user_id === $user->id;
    }

    public function delete(User $user, AudioAsset $model): bool
    {
        return $model->user_id === $user->id;
    }
}
