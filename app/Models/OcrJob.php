<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['submission_evidence_id', 'status', 'engine', 'engine_version', 'confidence', 'raw_text', 'extracted_data', 'error_message', 'started_at', 'finished_at'])]
class OcrJob extends Model
{
    protected function casts(): array
    {
        return ['confidence' => 'float', 'extracted_data' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(SubmissionEvidence::class, 'submission_evidence_id');
    }
}
