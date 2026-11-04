<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['election_id', 'election_contest_id', 'election_polling_station_id', 'correction_of_submission_id', 'submitter_id', 'status', 'entry_mode', 'source_name', 'source_method', 'source_notes', 'idempotency_key', 'version', 'submitted_at'])]
class VoteSubmission extends Model
{
    protected function casts(): array
    {
        return ['status' => SubmissionStatus::class, 'submitted_at' => 'immutable_datetime'];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(ElectionContest::class, 'election_contest_id');
    }

    public function eventTps(): BelongsTo
    {
        return $this->belongsTo(ElectionPollingStation::class, 'election_polling_station_id');
    }

    public function correctionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'correction_of_submission_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitter_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(VoteSubmissionItem::class);
    }

    public function totals(): HasOne
    {
        return $this->hasOne(VoteSubmissionTotal::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(SubmissionEvidence::class);
    }
}
