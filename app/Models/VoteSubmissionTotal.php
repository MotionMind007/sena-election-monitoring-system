<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['vote_submission_id', 'valid_votes', 'invalid_votes', 'total_votes', 'extra_fields'])]
class VoteSubmissionTotal extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['extra_fields' => 'array'];
    }
}
