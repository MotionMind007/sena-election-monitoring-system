<?php

namespace App\Models;

use App\Enums\ElectionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'year', 'type', 'scope_region_id', 'status', 'starts_at', 'created_by'])]
class Election extends Model
{
    protected function casts(): array
    {
        return ['status' => ElectionStatus::class, 'starts_at' => 'immutable_datetime'];
    }

    public function scopeRegion(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'scope_region_id');
    }

    public function contests(): HasMany
    {
        return $this->hasMany(ElectionContest::class);
    }

    public function regionSnapshots(): HasMany
    {
        return $this->hasMany(ElectionRegion::class);
    }

    public function pollingStations(): HasMany
    {
        return $this->hasMany(ElectionPollingStation::class);
    }

    public function eventAssignments(): HasMany
    {
        return $this->hasMany(EventAssignment::class);
    }

    public function electionParties(): HasMany
    {
        return $this->hasMany(ElectionParty::class);
    }

    public function electoralDistricts(): HasMany
    {
        return $this->hasMany(ElectoralDistrict::class);
    }
}
