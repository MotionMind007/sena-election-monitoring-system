<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Reporting\SaveVoteSubmission;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVoteSubmissionRequest;
use App\Models\ElectionContest;
use App\Models\ElectionPollingStation;
use App\Services\ScopeAccess;
use Illuminate\Http\JsonResponse;

class VoteSubmissionController extends Controller
{
    public function store(StoreVoteSubmissionRequest $request, ScopeAccess $scope, SaveVoteSubmission $action): JsonResponse
    {
        $contest = ElectionContest::query()->with('participants')->findOrFail($request->integer('election_contest_id'));
        $eventTps = ElectionPollingStation::query()->with(['election', 'pollingStation.region.parent'])->findOrFail($request->integer('election_polling_station_id'));
        abort_unless($scope->canAccessPollingStation($request->user(), $eventTps), 404);

        $submission = $action->handle($request->user(), $contest, $eventTps, $request->validated(), $request->file('evidence', []));

        return response()->json([
            'data' => [
                'id' => $submission->id,
                'status' => $submission->status->value,
                'version' => $submission->version,
                'entry_mode' => $submission->entry_mode,
                'total_votes' => $submission->totals->total_votes,
                'evidence_count' => $submission->evidence->count(),
            ],
        ], $submission->wasRecentlyCreated ? 201 : 200);
    }
}
