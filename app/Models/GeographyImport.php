<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_id', 'source', 'source_version', 'original_name', 'storage_path', 'sha256', 'status', 'summary', 'committed_at'])]
class GeographyImport extends Model
{
    protected function casts(): array
    {
        return ['status' => ImportStatus::class, 'summary' => 'array', 'committed_at' => 'immutable_datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
