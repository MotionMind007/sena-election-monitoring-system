<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['election_id', 'party_id', 'ballot_number', 'active'])]
class ElectionParty extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function electoralDistricts(): BelongsToMany
    {
        return $this->belongsToMany(ElectoralDistrict::class, 'electoral_district_parties');
    }
}
