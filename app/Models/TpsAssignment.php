<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'election_id', 'election_polling_station_id', 'assigned_by'])]
class TpsAssignment extends Model
{
    public function electionPollingStation(): BelongsTo
    {
        return $this->belongsTo(ElectionPollingStation::class);
    }
}
