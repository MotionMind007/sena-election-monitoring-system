<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'election_id', 'region_id', 'assigned_by'])]
class RegionAssignment extends Model {}
