<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vote_submission_id', 'contest_participant_id', 'votes', 'ballot_dimension'])]
class VoteSubmissionItem extends Model
{
    public $timestamps = false;

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ContestParticipant::class, 'contest_participant_id');
    }
}
