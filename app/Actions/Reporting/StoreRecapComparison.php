<?php

namespace App\Actions\Reporting;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\ContestStatus;
use App\Enums\RegionLevel;
use App\Enums\UserRole;
use App\Models\Election;
use App\Models\ElectionContest;
use App\Models\RecapComparison;
use App\Models\Region;
use App\Models\User;
use App\Services\RecapAggregate;
use App\Services\RegionHierarchy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StoreRecapComparison
{
    public function __construct(
        private readonly RecapAggregate $aggregate,
        private readonly RegionHierarchy $hierarchy,
        private readonly WriteAuditLog $audit,
    ) {}

    public function handle(User $actor, Election $election, ElectionContest $contest, Region $region, array $data, UploadedFile $evidence): RecapComparison
    {
        abort_unless($actor->hasRole(UserRole::SuperAdmin), 403);
        $this->validateContext($election, $contest, $region);

        $existing = RecapComparison::query()
            ->where('submitted_by', $actor->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if ($existing) {
            $this->validateIdempotentScope($existing, $election, $contest, $region);

            return $existing;
        }

        $disk = (string) config('filesystems.evidence', 'local');
        $extension = $evidence->guessExtension() ?: 'bin';
        $storageKey = sprintf('recap-evidence/%d/%s.%s', $election->id, Str::uuid(), $extension);
        $stored = Storage::disk($disk)->putFileAs(dirname($storageKey), $evidence, basename($storageKey));
        if ($stored === false) {
            throw ValidationException::withMessages(['evidence' => 'Bukti rekap gagal disimpan. Silakan coba lagi.']);
        }

        try {
            $comparison = DB::transaction(function () use ($actor, $election, $contest, $region, $data, $evidence, $disk, $storageKey): RecapComparison {
                ElectionContest::query()->whereKey($contest->id)->lockForUpdate()->firstOrFail();

                $existing = RecapComparison::query()
                    ->where('submitted_by', $actor->id)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();
                if ($existing) {
                    $this->validateIdempotentScope($existing, $election, $contest, $region);

                    return $existing;
                }

                $snapshot = $this->aggregate->build($election, $contest, $region);
                $expectedIds = $snapshot['participants']->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
                $submittedIds = collect($data['items'])->pluck('participant_id')->map(fn ($id) => (int) $id)->sort()->values();
                if ($expectedIds->isEmpty() || $expectedIds->all() !== $submittedIds->all()) {
                    throw ValidationException::withMessages(['items' => 'Semua peserta yang berlaku pada wilayah ini wajib diisi tepat satu kali.']);
                }

                $officialByParticipant = collect($data['items'])->mapWithKeys(fn (array $item) => [(int) $item['participant_id'] => (int) $item['official_votes']]);
                $officialValid = (int) $officialByParticipant->sum();
                $officialInvalid = (int) $data['official_invalid_votes'];
                $officialTotal = $officialValid + $officialInvalid;
                $hasItemDiscrepancy = $snapshot['items']->contains(fn (array $item) => $officialByParticipant[$item['participant_id']] !== $item['automatic_votes']);
                $hasDiscrepancy = $hasItemDiscrepancy
                    || $officialInvalid !== $snapshot['invalid_votes']
                    || $officialTotal !== $snapshot['total_votes'];
                $version = ((int) RecapComparison::query()
                    ->where('election_id', $election->id)
                    ->where('election_contest_id', $contest->id)
                    ->where('region_id', $region->id)
                    ->max('version')) + 1;

                $comparison = RecapComparison::query()->create([
                    'election_id' => $election->id,
                    'election_contest_id' => $contest->id,
                    'region_id' => $region->id,
                    'submitted_by' => $actor->id,
                    'idempotency_key' => $data['idempotency_key'],
                    'version' => $version,
                    'level' => $region->level->value,
                    'snapshot_verified_tps_count' => $snapshot['verified_tps_count'],
                    'snapshot_at' => now(),
                    'official_valid_votes' => $officialValid,
                    'official_invalid_votes' => $officialInvalid,
                    'official_total_votes' => $officialTotal,
                    'automatic_valid_votes' => $snapshot['valid_votes'],
                    'automatic_invalid_votes' => $snapshot['invalid_votes'],
                    'automatic_total_votes' => $snapshot['total_votes'],
                    'has_discrepancy' => $hasDiscrepancy,
                    'evidence_disk' => $disk,
                    'evidence_key' => $storageKey,
                    'evidence_original_name' => $evidence->getClientOriginalName(),
                    'evidence_sha256' => hash_file('sha256', $evidence->getRealPath()),
                    'evidence_media_type' => $evidence->getMimeType(),
                    'evidence_size_bytes' => $evidence->getSize(),
                    'notes' => $data['notes'] ?? null,
                ]);

                foreach ($snapshot['items'] as $item) {
                    $official = $officialByParticipant[$item['participant_id']];
                    $comparison->items()->create([
                        'contest_participant_id' => $item['participant_id'],
                        'official_votes' => $official,
                        'automatic_votes' => $item['automatic_votes'],
                        'delta' => $official - $item['automatic_votes'],
                        'ballot_dimension' => $item['ballot_dimension'],
                    ]);
                }

                $this->audit->handle($actor, 'recap_comparison.created', $comparison, after: $comparison->toArray(), metadata: [
                    'event_id' => $election->id,
                    'contest_id' => $contest->id,
                    'region_id' => $region->id,
                    'version' => $version,
                    'has_discrepancy' => $hasDiscrepancy,
                    'evidence_sha256' => $comparison->evidence_sha256,
                ]);

                return $comparison;
            }, 3);

            if ($comparison->evidence_key !== $storageKey) {
                Storage::disk($disk)->delete($storageKey);
            }

            return $comparison->load(['items.participant', 'region', 'contest', 'submitter']);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($storageKey);
            throw $exception;
        }
    }

    private function validateContext(Election $election, ElectionContest $contest, Region $region): void
    {
        $allowedLevels = [RegionLevel::District, RegionLevel::Regency, RegionLevel::City, RegionLevel::Province];
        if ($contest->election_id !== $election->id
            || $contest->status !== ContestStatus::Active
            || ! in_array($region->level, $allowedLevels, true)
            || ! $this->hierarchy->contains($election->scope_region_id, $region->id)) {
            throw ValidationException::withMessages(['region_id' => 'Kontes atau wilayah rekap tidak termasuk dalam scope event.']);
        }
    }

    private function validateIdempotentScope(RecapComparison $comparison, Election $election, ElectionContest $contest, Region $region): void
    {
        if ($comparison->election_id !== $election->id
            || $comparison->election_contest_id !== $contest->id
            || $comparison->region_id !== $region->id) {
            throw ValidationException::withMessages(['idempotency_key' => 'Kunci idempotensi sudah digunakan untuk rekap lain.']);
        }
    }
}
