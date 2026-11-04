<?php

namespace App\Actions\Elections;

use App\Actions\Audit\WriteAuditLog;
use App\Models\Election;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteElection
{
    public function __construct(private readonly WriteAuditLog $audit) {}

    public function handle(User $actor, Election $election): void
    {
        $hasOperationalData = DB::table('vote_submissions')->where('election_id', $election->id)->exists()
            || DB::table('verified_results')->where('election_id', $election->id)->exists()
            || DB::table('recap_comparisons')->where('election_id', $election->id)->exists();
        if ($hasOperationalData) {
            throw ValidationException::withMessages([
                'event' => 'Event tidak dapat dihapus karena sudah memiliki laporan, hasil verifikasi, atau rekap. Tutup/arsipkan event untuk menjaga audit.',
            ]);
        }

        DB::transaction(function () use ($actor, $election): void {
            $this->audit->handle($actor, 'election.deleted', $election, before: $election->toArray(), metadata: [
                'name' => $election->name,
                'year' => $election->year,
                'type' => $election->type,
            ]);
            $election->delete();
        });
    }
}
