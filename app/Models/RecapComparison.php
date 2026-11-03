<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'election_id', 'election_contest_id', 'region_id', 'submitted_by', 'idempotency_key', 'version', 'level',
    'snapshot_verified_tps_count', 'snapshot_at', 'official_valid_votes', 'official_invalid_votes',
    'official_total_votes', 'automatic_valid_votes', 'automatic_invalid_votes', 'automatic_total_votes',
    'has_discrepancy', 'evidence_disk', 'evidence_key', 'evidence_original_name', 'evidence_sha256',
    'evidence_media_type', 'evidence_size_bytes', 'notes',
])]
class RecapComparison extends Model
{
    protected function casts(): array
    {
        return ['has_discrepancy' => 'boolean', 'snapshot_at' => 'immutable_datetime'];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(ElectionContest::class, 'election_contest_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecapComparisonItem::class);
    }
}
