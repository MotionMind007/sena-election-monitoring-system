<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['election_id', 'name', 'active'])]
class ElectoralDistrict extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'electoral_district_regions');
    }

    public function electionParties(): BelongsToMany
    {
        return $this->belongsToMany(ElectionParty::class, 'electoral_district_parties');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ContestParticipant::class);
    }
}
