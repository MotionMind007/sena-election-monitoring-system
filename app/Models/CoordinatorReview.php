<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['vote_submission_id', 'reviewer_id', 'action', 'notes'])]
class CoordinatorReview extends Model {}
