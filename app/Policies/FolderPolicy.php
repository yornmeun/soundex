<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\User;

class FolderPolicy
{
    public function view(User $user, Folder $model): bool
    {
        return $model->user_id === $user->id;
    }

    public function update(User $user, Folder $model): bool
    {
        return $model->user_id === $user->id;
    }

    public function delete(User $user, Folder $model): bool
    {
        return $model->user_id === $user->id;
    }
}
