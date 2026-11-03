<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recap_comparison_id', 'contest_participant_id', 'official_votes', 'automatic_votes', 'delta', 'ballot_dimension'])]
class RecapComparisonItem extends Model
{
    public $timestamps = false;

    public function comparison(): BelongsTo
    {
        return $this->belongsTo(RecapComparison::class, 'recap_comparison_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ContestParticipant::class, 'contest_participant_id');
    }
}
