<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $logs = AuditLog::query()->with('actor:id,name,email')
            ->when($request->string('action')->toString(), fn ($query, $action) => $query->where('action', 'like', '%'.$action.'%'))
            ->latest('id')->paginate(30)->withQueryString();

        return Inertia::render('Admin/Audit/Index', [
            'filters' => ['action' => $request->string('action')->toString()],
            'logs' => $logs->through(fn (AuditLog $log) => [
                'id' => $log->id, 'action' => $log->action, 'actor' => $log->actor?->name ?? 'Sistem',
                'object_type' => class_basename($log->object_type), 'object_id' => $log->object_id,
                'before_hash' => $log->before_hash, 'after_hash' => $log->after_hash,
                'metadata' => $log->metadata, 'created_at' => $log->created_at?->timezone('Asia/Jayapura')->format('d M Y, H:i').' WIT',
            ]),
        ]);
    }
}
