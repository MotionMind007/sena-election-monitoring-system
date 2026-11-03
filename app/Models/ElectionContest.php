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

    public function participantsForDistrict(?int $districtRegionId): Collection
    {
        $participants = $this->participants()->where('active', true)
            ->with(['electionParty.electoralDistricts.regions', 'electoralDistrict'])->get();
        if ($this->type !== 'legislative' || ! $districtRegionId) {
            return $participants;
        }

        return $participants->filter(function (ContestParticipant $participant) use ($districtRegionId): bool {
            if ($participant->participant_type === 'candidate') {
                return $participant->electoralDistrict?->regions()->whereKey($districtRegionId)->exists() ?? false;
            }
            if ($participant->participant_type === 'party') {
                return $participant->electionParty?->electoralDistricts->contains(
                    fn (ElectoralDistrict $dapil) => $dapil->regions->contains('id', $districtRegionId)
                ) ?? false;
            }

            return false;
        })->values();
    }
}
