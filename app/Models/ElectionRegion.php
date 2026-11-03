<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['election_id', 'region_id', 'code_snapshot', 'name_snapshot', 'level_snapshot', 'source_snapshot', 'source_version_snapshot'])]
class ElectionRegion extends Model
{
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
