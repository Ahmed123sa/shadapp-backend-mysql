<?php

namespace App\Policies;

use App\Models\FileEntry;
use Illuminate\Auth\Access\HandlesAuthorization;

class FileEntryPolicy
{
    use HandlesAuthorization;

    public function viewAny($user): bool
    {
        if ($user instanceof \App\Models\Client) return true;
        return $user instanceof \App\Models\User && in_array($user->role, [\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ACCOUNT_MANAGER]);
    }
}
