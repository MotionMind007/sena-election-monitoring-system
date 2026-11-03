<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['vote_submission_id', 'disk', 'storage_key', 'preview_key', 'original_name', 'sha256', 'media_type', 'preview_media_type', 'size_bytes', 'preview_size_bytes', 'uploaded_at'])]
class SubmissionEvidence extends Model
{
    protected function casts(): array
    {
        return ['uploaded_at' => 'immutable_datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(VoteSubmission::class, 'vote_submission_id');
    }

    public function ocrJob(): HasOne
    {
        return $this->hasOne(OcrJob::class);
    }
}
