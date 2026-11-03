<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Election;
use App\Models\ElectionPollingStation;
use App\Models\Region;
use App\Models\User;

class ScopeAccess
{
    public function canAccessElection(User $user, Election $election): bool
    {
        return $user->hasRole(UserRole::SuperAdmin)
            || $user->eventAssignments()->where('election_id', $election->id)->exists();
    }

    public function canAccessRegion(User $user, Election $election, Region $region): bool
    {
        if ($user->hasRole(UserRole::SuperAdmin)) {
            return true;
        }

        if (! $this->canAccessElection($user, $election)) {
            return false;
        }

        $assignedIds = $user->regionAssignments()->where('election_id', $election->id)->pluck('region_id')->all();

        if ($assignedIds === []) {
            return $user->hasRole(UserRole::Witness)
                && $user->tpsAssignments()
                    ->where('election_id', $election->id)
                    ->whereHas('electionPollingStation.pollingStation', fn ($query) => $query->where('region_id', $region->id))
                    ->exists();
        }

        $cursor = $region;

        while ($cursor !== null) {
            if (in_array($cursor->id, $assignedIds, true)) {
                return true;
            }

            $cursor = $cursor->parent;
        }

        return false;
    }

    public function canAccessPollingStation(User $user, ElectionPollingStation $eventTps): bool
    {
        if ($user->hasRole(UserRole::SuperAdmin)) {
            return true;
        }

        if ($user->hasRole(UserRole::Witness)) {
            return $user->tpsAssignments()->where('election_polling_station_id', $eventTps->id)->exists();
        }

        return $this->canAccessRegion($user, $eventTps->election, $eventTps->pollingStation->region);
    }
}
