<?php

namespace App\Models;

use App\Enums\ContestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['election_id', 'name', 'type', 'ballot_schema_version', 'status'])]
class ElectionContest extends Model
{
    protected function casts(): array
    {
        return ['status' => ContestStatus::class];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ContestParticipant::class)->orderBy('display_order');
    }

    public function participantsForRegion(?Region $village): Collection
    {
        $participants = $this->participants()->where('active', true)
            ->with(['electionParty.electoralDistricts.regions', 'electoralDistrict'])->get();
        if ($this->type !== 'legislative' || ! $village) {
            return $participants;
        }

        $coverageMatches = fn (ElectoralDistrict $dapil): bool => $dapil->regions->contains('id', $village->id)
            || ($village->parent_id && $dapil->regions->contains('id', $village->parent_id));

        return $participants->filter(function (ContestParticipant $participant) use ($coverageMatches): bool {
            if ($participant->participant_type === 'candidate') {
                return $participant->electoralDistrict ? $coverageMatches($participant->electoralDistrict) : false;
            }
            if ($participant->participant_type === 'party') {
                return $participant->electionParty?->electoralDistricts->contains(
                    $coverageMatches
                ) ?? false;
            }

            return false;
        })->values();
    }
}
