<?php

namespace App\Policies;

use App\Models\UploadedFile;
use App\Models\User;

class UploadedFilePolicy
{
    public function view(User $user, UploadedFile $file): bool
    {
        return $file->user_id === $user->id;
    }
}
