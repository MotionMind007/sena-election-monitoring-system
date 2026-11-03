<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['election_contest_id', 'participant_type', 'ballot_number', 'display_name', 'photo_path', 'election_party_id', 'electoral_district_id', 'display_order', 'active'])]
class ContestParticipant extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(ElectionContest::class, 'election_contest_id');
    }

    public function electionParty(): BelongsTo
    {
        return $this->belongsTo(ElectionParty::class);
    }

    public function electoralDistrict(): BelongsTo
    {
        return $this->belongsTo(ElectoralDistrict::class);
    }

    public function supportingParties(): BelongsToMany
    {
        return $this->belongsToMany(Party::class, 'participant_supporting_parties');
    }
}
