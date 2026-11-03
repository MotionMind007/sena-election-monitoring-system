<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\OcrJob;
use App\Models\VoteSubmission;
use Inertia\Inertia;
use Inertia\Response;

class OperationsController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Admin/Operations/Index', [
            'events' => Election::query()->with('contests:id,election_id,name')->orderByDesc('year')->get(['id', 'name', 'year']),
            'alerts' => [
                ['label' => 'Menunggu keputusan', 'count' => VoteSubmission::query()->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::Recommended->value])->count(), 'tone' => 'amber'],
                ['label' => 'Perlu revisi', 'count' => VoteSubmission::query()->where('status', SubmissionStatus::RevisionRequested->value)->count(), 'tone' => 'orange'],
                ['label' => 'Konflik laporan', 'count' => VoteSubmission::query()->where('status', SubmissionStatus::Conflict->value)->count(), 'tone' => 'rose'],
                ['label' => 'OCR gagal', 'count' => OcrJob::query()->where('status', 'failed')->count(), 'tone' => 'violet'],
            ],
            'runtime' => [
                'queue' => config('queue.default'), 'database' => config('database.default'),
                'storage' => config('filesystems.default'), 'environment' => app()->environment(),
            ],
        ]);
    }
}
