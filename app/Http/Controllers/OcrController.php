<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEvidenceOcr;
use App\Models\OcrJob;
use App\Models\SubmissionEvidence;
use App\Services\ScopeAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OcrController extends Controller
{
    public function store(Request $request, SubmissionEvidence $evidence, ScopeAccess $scope): RedirectResponse
    {
        $evidence->load('submission.eventTps.election', 'submission.eventTps.pollingStation.region.parent');
        abort_unless($scope->canAccessPollingStation($request->user(), $evidence->submission->eventTps), 404);
        abort_unless($evidence->submission->submitter_id === $request->user()->id, 403);

        $ocrJob = OcrJob::query()->updateOrCreate(
            ['submission_evidence_id' => $evidence->id],
            ['status' => 'pending', 'engine' => 'tesseract-local', 'confidence' => null, 'raw_text' => null, 'extracted_data' => null, 'error_message' => null, 'started_at' => null, 'finished_at' => null],
        );
        ProcessEvidenceOcr::dispatch($ocrJob->id)->afterCommit();

        return back()->with('success', 'OCR dijadwalkan. Input manual tetap dapat digunakan.');
    }
}
