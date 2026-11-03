<?php

namespace App\Actions\Verification;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\CoordinatorReview;
use App\Models\User;
use App\Models\VoteSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewSubmission
{
    public function __construct(private readonly WriteAuditLog $audit) {}

    public function handle(User $actor, VoteSubmission $submission, string $action, ?string $notes): VoteSubmission
    {
        if (! $actor->hasRole(UserRole::Coordinator) || $submission->submitter_id === $actor->id) {
            throw ValidationException::withMessages(['action' => 'Koordinator tidak boleh mereview laporan sendiri.']);
        }
        if (! in_array($submission->status, [SubmissionStatus::Submitted, SubmissionStatus::Recommended], true)) {
            throw ValidationException::withMessages(['action' => 'Status laporan tidak dapat direview.']);
        }

        return DB::transaction(function () use ($actor, $submission, $action, $notes) {
            CoordinatorReview::query()->updateOrCreate(['vote_submission_id' => $submission->id, 'reviewer_id' => $actor->id], ['action' => $action, 'notes' => $notes]);
            $submission->update(['status' => $action === 'recommend' ? SubmissionStatus::Recommended : SubmissionStatus::RevisionRequested]);
            $this->audit->handle($actor, 'vote_submission.'.$action, $submission, metadata: ['notes' => $notes]);

            return $submission->fresh();
        });
    }
}
