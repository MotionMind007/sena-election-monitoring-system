<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Election;
use App\Models\User;
use App\Services\ScopeAccess;

class ElectionPolicy
{
    public function __construct(private readonly ScopeAccess $scope) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Election $election): bool
    {
        return $this->scope->canAccessElection($user, $election);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::SuperAdmin);
    }

    public function update(User $user): bool
    {
        return $user->hasRole(UserRole::SuperAdmin);
    }

    public function delete(User $user): bool
    {
        return $user->hasRole(UserRole::SuperAdmin);
    }
}
