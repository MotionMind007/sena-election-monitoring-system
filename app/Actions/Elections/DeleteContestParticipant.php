<?php

namespace App\Actions\Elections;

use App\Actions\Audit\WriteAuditLog;
use App\Models\ContestParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DeleteContestParticipant
{
    public function __construct(private readonly WriteAuditLog $audit) {}

    public function handle(User $actor, ContestParticipant $participant): void
    {
        $isUsed = DB::table('vote_submission_items')->where('contest_participant_id', $participant->id)->exists()
            || DB::table('verified_result_items')->where('contest_participant_id', $participant->id)->exists()
            || DB::table('recap_comparison_items')->where('contest_participant_id', $participant->id)->exists();
        if ($isUsed) {
            throw ValidationException::withMessages([
                'participant' => 'Kandidat tidak dapat dihapus karena sudah digunakan pada laporan suara, hasil verifikasi, atau rekap. Nonaktifkan kandidat bila perlu.',
            ]);
        }

        $photoPath = $participant->photo_path;
        DB::transaction(function () use ($actor, $participant): void {
            $this->audit->handle($actor, 'contest_participant.deleted', $participant, before: $participant->toArray());
            $participant->delete();
        });
        if ($photoPath) {
            Storage::disk('local')->delete($photoPath);
        }
    }
}
