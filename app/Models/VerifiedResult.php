<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['election_id', 'election_contest_id', 'election_polling_station_id', 'source_submission_id', 'active', 'verified_at', 'verified_by', 'supersedes_id'])]
class VerifiedResult extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean', 'verified_at' => 'immutable_datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(VerifiedResultItem::class);
    }

    public function eventTps(): BelongsTo
    {
        return $this->belongsTo(ElectionPollingStation::class, 'election_polling_station_id');
    }

    public function sourceSubmission(): BelongsTo
    {
        return $this->belongsTo(VoteSubmission::class, 'source_submission_id');
    }
}
