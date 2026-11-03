<?php

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Models\User;

class WriteAuditLog
{
    public function handle(User $actor, string $action, object $object, ?array $before = null, ?array $after = null, array $metadata = []): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'object_type' => $object::class,
            'object_id' => (string) $object->id,
            'before_hash' => $before === null ? null : hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
            'after_hash' => $after === null ? null : hash('sha256', json_encode($after, JSON_THROW_ON_ERROR)),
            'metadata' => $metadata,
        ]);
    }
}
