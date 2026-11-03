<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['region_id', 'code', 'number', 'location_label', 'source', 'created_by'])]
class PollingStation extends Model
{
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function elections(): HasMany
    {
        return $this->hasMany(ElectionPollingStation::class);
    }
}
