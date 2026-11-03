<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['election_id', 'polling_station_id', 'eligible', 'status'])]
class ElectionPollingStation extends Model
{
    protected function casts(): array
    {
        return ['eligible' => 'boolean'];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    public function pollingStation(): BelongsTo
    {
        return $this->belongsTo(PollingStation::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TpsAssignment::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(VoteSubmission::class);
    }
}
