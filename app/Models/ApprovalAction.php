<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['vote_submission_id', 'admin_id', 'action', 'reason'])]
class ApprovalAction extends Model {}
