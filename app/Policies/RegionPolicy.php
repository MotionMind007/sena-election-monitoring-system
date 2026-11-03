<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Region;
use App\Models\User;

class RegionPolicy
{
    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::SuperAdmin);
    }

    public function update(User $user, Region $region): bool
    {
        return $user->hasRole(UserRole::SuperAdmin);
    }
}
