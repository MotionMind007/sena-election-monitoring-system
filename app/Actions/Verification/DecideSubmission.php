<?php

namespace App\Actions\Verification;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\ApprovalAction;
use App\Models\ElectionContest;
use App\Models\User;
use App\Models\VerifiedResult;
use App\Models\VoteSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DecideSubmission
{
    public function __construct(private readonly WriteAuditLog $audit) {}

    public function handle(User $actor, VoteSubmission $submission, string $action, ?string $reason): VoteSubmission
    {
        if (! $actor->hasRole(UserRole::SuperAdmin)) {
            abort(403);
        }
        if ($submission->submitter_id === $actor->id && $submission->entry_mode !== 'admin_proxy') {
            throw ValidationException::withMessages(['action' => 'Super Admin tidak boleh memutuskan laporan yang diinput sendiri kecuali transkrip admin proxy dengan bukti sumber.']);
        }

        return DB::transaction(function () use ($actor, $submission, $action, $reason) {
            $locked = VoteSubmission::query()->with(['items', 'evidence'])->lockForUpdate()->findOrFail($submission->id);
            ElectionContest::query()->whereKey($locked->election_contest_id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [SubmissionStatus::Submitted, SubmissionStatus::Recommended], true)) {
                throw ValidationException::withMessages(['action' => 'Laporan sudah diputuskan atau membutuhkan koreksi.']);
            }
            if ($action === 'approve') {
                if ($locked->evidence->isEmpty()) {
                    throw ValidationException::withMessages(['action' => 'Laporan tanpa bukti tidak dapat disetujui.']);
                }
                $previous = VerifiedResult::query()->where('election_id', $locked->election_id)->where('election_contest_id', $locked->election_contest_id)->where('election_polling_station_id', $locked->election_polling_station_id)->where('active', true)->lockForUpdate()->first();
                $previous?->update(['active' => false]);
                $verified = VerifiedResult::query()->create(['election_id' => $locked->election_id, 'election_contest_id' => $locked->election_contest_id, 'election_polling_station_id' => $locked->election_polling_station_id, 'source_submission_id' => $locked->id, 'active' => true, 'verified_at' => now(), 'verified_by' => $actor->id, 'supersedes_id' => $previous?->id]);
                foreach ($locked->items as $item) {
                    $verified->items()->create(['contest_participant_id' => $item->contest_participant_id, 'votes' => $item->votes, 'ballot_dimension' => $item->ballot_dimension]);
                }
                $locked->update(['status' => SubmissionStatus::Approved]);
                VoteSubmission::query()->where('election_id', $locked->election_id)->where('election_contest_id', $locked->election_contest_id)->where('election_polling_station_id', $locked->election_polling_station_id)->whereKeyNot($locked->id)->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::Recommended->value])->update(['status' => SubmissionStatus::Conflict->value]);
            } else {
                $locked->update(['status' => $action === 'reject' ? SubmissionStatus::Rejected : SubmissionStatus::RevisionRequested]);
            }
            ApprovalAction::query()->create(['vote_submission_id' => $locked->id, 'admin_id' => $actor->id, 'action' => $action, 'reason' => $reason]);
            $this->audit->handle($actor, 'vote_submission.'.$action, $locked, metadata: ['reason' => $reason, 'self_approved_admin_proxy' => $locked->submitter_id === $actor->id && $locked->entry_mode === 'admin_proxy']);

            return $locked->fresh();
        }, 3);
    }
}
