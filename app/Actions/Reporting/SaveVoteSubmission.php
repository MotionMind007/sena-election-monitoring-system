<?php

namespace App\Actions\Reporting;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessEvidenceOcr;
use App\Models\ElectionContest;
use App\Models\ElectionPollingStation;
use App\Models\OcrJob;
use App\Models\User;
use App\Models\VoteSubmission;
use App\Services\EvidenceImagePreview;
use App\Services\EvidenceImageQuality;
use App\Services\EvidenceMalwareScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SaveVoteSubmission
{
    private readonly EvidenceImageQuality $imageQuality;

    private readonly EvidenceImagePreview $imagePreview;

    private readonly EvidenceMalwareScanner $malwareScanner;

    public function __construct(
        private readonly WriteAuditLog $audit,
        ?EvidenceImageQuality $imageQuality = null,
        ?EvidenceImagePreview $imagePreview = null,
        ?EvidenceMalwareScanner $malwareScanner = null,
    ) {
        $this->imageQuality = $imageQuality ?? new EvidenceImageQuality;
        $this->imagePreview = $imagePreview ?? new EvidenceImagePreview;
        $this->malwareScanner = $malwareScanner ?? new EvidenceMalwareScanner;
    }

    /** @param array<int, UploadedFile> $evidence */
    public function handle(User $actor, ElectionContest $contest, ElectionPollingStation $eventTps, array $data, array $evidence): VoteSubmission
    {
        if ($contest->election_id !== $eventTps->election_id || (int) $data['election_id'] !== $contest->election_id || ! $eventTps->eligible) {
            throw ValidationException::withMessages(['election_contest_id' => 'Kontes dan TPS harus berasal dari event yang sama.']);
        }

        foreach ($evidence as $file) {
            $this->imageQuality->assertAcceptable($file);
            try {
                $this->malwareScanner->assertClean($file);
            } catch (ValidationException $exception) {
                $this->audit->handle($actor, 'evidence.malware_scan_rejected', $actor, metadata: [
                    'original_name' => $file->getClientOriginalName(),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                ]);

                throw $exception;
            }
        }

        $districtRegionId = $eventTps->pollingStation()->with('region')->first()?->region?->parent_id;
        $applicableParticipants = $contest->participantsForDistrict($districtRegionId);
        $participantIds = $applicableParticipants->pluck('id')->sort()->values();
        $submittedIds = collect($data['items'])->pluck('participant_id')->map(fn ($id) => (int) $id)->sort()->values();
        if ($participantIds->isEmpty() || $participantIds->all() !== $submittedIds->all()) {
            throw ValidationException::withMessages(['items' => 'Semua peserta aktif pada kontes wajib diisi tepat satu kali.']);
        }

        $storedObjects = [];

        try {
            return DB::transaction(function () use ($actor, $contest, $eventTps, $data, $evidence, &$storedObjects): VoteSubmission {
                $submission = VoteSubmission::query()
                    ->where('submitter_id', $actor->id)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->lockForUpdate()
                    ->first();

                if ($submission && ($submission->election_contest_id !== $contest->id || $submission->election_polling_station_id !== $eventTps->id)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Kunci idempotensi sudah digunakan untuk laporan lain.']);
                }

                if ($submission?->status === SubmissionStatus::Submitted) {
                    return $submission->load(['items.participant', 'totals', 'evidence']);
                }

                if ($submission && $submission->status !== SubmissionStatus::Draft) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Laporan yang sudah diproses tidak dapat diubah. Gunakan laporan koreksi baru.',
                    ]);
                }

                if (! $submission) {
                    $version = ((int) VoteSubmission::query()
                        ->where('election_id', $contest->election_id)
                        ->where('election_contest_id', $contest->id)
                        ->where('election_polling_station_id', $eventTps->id)
                        ->lockForUpdate()
                        ->max('version')) + 1;

                    $submission = VoteSubmission::query()->create([
                        'election_id' => $contest->election_id,
                        'election_contest_id' => $contest->id,
                        'election_polling_station_id' => $eventTps->id,
                        'submitter_id' => $actor->id,
                        'status' => SubmissionStatus::Draft,
                        'entry_mode' => $actor->hasRole(UserRole::Witness) ? 'direct' : ($actor->hasRole(UserRole::Coordinator) ? 'coordinator_proxy' : 'admin_proxy'),
                        'idempotency_key' => $data['idempotency_key'],
                        'version' => $version,
                    ]);
                }

                $submission->update([
                    'source_name' => $actor->hasRole(UserRole::Witness) ? $actor->name : $data['source_name'],
                    'source_method' => $actor->hasRole(UserRole::Witness) ? 'direct' : $data['source_method'],
                    'source_notes' => $data['source_notes'] ?? null,
                ]);

                $submission->items()->delete();
                $validVotes = 0;
                $participantTypes = $contest->participants()->whereIn('id', collect($data['items'])->pluck('participant_id'))->pluck('participant_type', 'id');
                foreach ($data['items'] as $item) {
                    $votes = (int) $item['votes'];
                    $validVotes += $votes;
                    $submission->items()->create([
                        'contest_participant_id' => $item['participant_id'],
                        'votes' => $votes,
                        'ballot_dimension' => $participantTypes[$item['participant_id']] === 'party' ? 'party' : 'candidate',
                    ]);
                }
                $invalidVotes = (int) $data['invalid_votes'];
                $ocrJob = isset($data['ocr_job_id']) ? OcrJob::query()->with('evidence')->find($data['ocr_job_id']) : null;
                if ($ocrJob && ($ocrJob->status !== 'completed' || $ocrJob->evidence->vote_submission_id !== $submission->id)) {
                    throw ValidationException::withMessages(['ocr_job_id' => 'Saran OCR tidak valid untuk draft ini.']);
                }
                $submission->totals()->updateOrCreate([], [
                    'valid_votes' => $validVotes,
                    'invalid_votes' => $invalidVotes,
                    'total_votes' => $validVotes + $invalidVotes,
                    'extra_fields' => $ocrJob ? ['ocr_job_id' => $ocrJob->id, 'ocr_confirmed' => (bool) ($data['ocr_confirmed'] ?? false)] : null,
                ]);

                foreach ($evidence as $file) {
                    $sha256 = hash_file('sha256', $file->getRealPath());
                    if ($submission->evidence()->where('sha256', $sha256)->exists()) {
                        continue;
                    }
                    if ($submission->evidence()->count() >= 1) {
                        throw ValidationException::withMessages(['evidence' => 'Setiap laporan hanya dapat memiliki satu foto bukti.']);
                    }
                    $extension = $file->guessExtension() ?: 'bin';
                    $storageKey = sprintf('evidence/%d/%s.%s', $submission->id, Str::uuid(), $extension);
                    $disk = (string) config('filesystems.evidence', 'local');
                    $stored = Storage::disk($disk)->putFileAs(dirname($storageKey), $file, basename($storageKey));
                    if ($stored === false) {
                        throw ValidationException::withMessages(['evidence' => 'Bukti laporan gagal disimpan. Silakan coba lagi.']);
                    }
                    $storedObjects[] = [$disk, $storageKey];
                    $preview = $this->imagePreview->store($file, $disk, $submission->id);
                    $storedObjects[] = [$disk, $preview['key']];
                    $storedEvidence = $submission->evidence()->create([
                        'disk' => $disk, 'storage_key' => $storageKey, 'original_name' => $file->getClientOriginalName(),
                        'preview_key' => $preview['key'], 'preview_media_type' => $preview['media_type'], 'preview_size_bytes' => $preview['size_bytes'],
                        'sha256' => $sha256, 'media_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'uploaded_at' => now(),
                    ]);
                    $ocrJob = OcrJob::query()->create(['submission_evidence_id' => $storedEvidence->id, 'status' => 'pending', 'engine' => 'tesseract-local']);
                    ProcessEvidenceOcr::dispatch($ocrJob->id)->afterCommit();
                }

                if ($data['intent'] === 'submit') {
                    if (isset($data['ocr_job_id']) && ! ($data['ocr_confirmed'] ?? false)) {
                        throw ValidationException::withMessages(['ocr_confirmed' => 'Saran OCR wajib diperiksa dan dikonfirmasi sebelum laporan dikirim.']);
                    }
                    if ($submission->evidence()->count() === 0) {
                        throw ValidationException::withMessages(['evidence' => 'Minimal satu foto bukti wajib sebelum laporan dikirim.']);
                    }
                    $submission->update(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()]);
                }

                $this->audit->handle($actor, $data['intent'] === 'submit' ? 'vote_submission.submitted' : 'vote_submission.draft_saved', $submission, after: $submission->fresh()->toArray(), metadata: [
                    'entry_mode' => $submission->entry_mode, 'source_method' => $submission->source_method, 'version' => $submission->version,
                ]);

                return $submission->load(['items.participant', 'totals', 'evidence']);
            });
        } catch (Throwable $exception) {
            foreach ($storedObjects as [$disk, $storageKey]) {
                Storage::disk($disk)->delete($storageKey);
            }

            throw $exception;
        }
    }
}
