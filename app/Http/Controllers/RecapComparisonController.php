<?php

namespace App\Http\Controllers;

use App\Actions\Reporting\StoreRecapComparison;
use App\Enums\RegionLevel;
use App\Enums\UserRole;
use App\Http\Requests\StoreRecapComparisonRequest;
use App\Models\Election;
use App\Models\ElectionContest;
use App\Models\RecapComparison;
use App\Models\Region;
use App\Services\RecapAggregate;
use App\Services\RegionHierarchy;
use App\Services\ScopeAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecapComparisonController extends Controller
{
    public function preview(Request $request, ScopeAccess $scope, RegionHierarchy $hierarchy, RecapAggregate $aggregate): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole(UserRole::SuperAdmin, UserRole::Coordinator), 403);
        $data = $request->validate([
            'event' => ['required', 'integer', 'exists:elections,id'],
            'contest' => ['required', 'integer', 'exists:election_contests,id'],
            'region' => ['required', 'integer', 'exists:regions,id'],
        ]);
        $election = Election::query()->findOrFail($data['event']);
        $contest = ElectionContest::query()->where('election_id', $election->id)->where('status', 'active')->findOrFail($data['contest']);
        $region = Region::query()->findOrFail($data['region']);
        $this->authorizeContext($request, $election, $region, $scope, $hierarchy);
        $snapshot = $aggregate->build($election, $contest, $region);
        $latest = RecapComparison::query()
            ->where('election_id', $election->id)
            ->where('election_contest_id', $contest->id)
            ->where('region_id', $region->id)
            ->with('items')
            ->latest('version')
            ->first();

        return response()->json([
            'region' => ['id' => $region->id, 'name' => $region->name, 'level' => $region->level->value],
            'verified_tps_count' => $snapshot['verified_tps_count'],
            'automatic' => [
                'valid_votes' => $snapshot['valid_votes'],
                'invalid_votes' => $snapshot['invalid_votes'],
                'total_votes' => $snapshot['total_votes'],
            ],
            'items' => $snapshot['items'],
            'latest' => $latest ? $this->summary($latest, $snapshot) : null,
        ]);
    }

    public function store(StoreRecapComparisonRequest $request, StoreRecapComparison $action): RedirectResponse
    {
        $election = Election::query()->findOrFail($request->integer('election_id'));
        $contest = ElectionContest::query()->findOrFail($request->integer('election_contest_id'));
        $region = Region::query()->findOrFail($request->integer('region_id'));
        $comparison = $action->handle($request->user(), $election, $contest, $region, $request->validated(), $request->file('evidence'));

        return redirect()->route('reports.index', ['event' => $election->id])
            ->with('success', $comparison->has_discrepancy
                ? 'Rekap tersimpan. Sistem menemukan selisih terhadap agregasi TPS.'
                : 'Rekap tersimpan dan sesuai dengan agregasi TPS.');
    }

    public function evidence(Request $request, RecapComparison $comparison, ScopeAccess $scope, RegionHierarchy $hierarchy): StreamedResponse
    {
        $comparison->load(['election', 'region']);
        abort_unless($request->user()->hasAnyRole(UserRole::SuperAdmin, UserRole::Coordinator), 403);
        $this->authorizeContext($request, $comparison->election, $comparison->region, $scope, $hierarchy);
        abort_unless(Storage::disk($comparison->evidence_disk)->exists($comparison->evidence_key), 404);

        return Storage::disk($comparison->evidence_disk)->download(
            $comparison->evidence_key,
            $comparison->evidence_original_name,
            ['Content-Type' => $comparison->evidence_media_type, 'Cache-Control' => 'private, no-store']
        );
    }

    private function authorizeContext(Request $request, Election $election, Region $region, ScopeAccess $scope, RegionHierarchy $hierarchy): void
    {
        $allowedLevels = [RegionLevel::District, RegionLevel::Regency, RegionLevel::City, RegionLevel::Province];
        if (! in_array($region->level, $allowedLevels, true)
            || ! $hierarchy->contains($election->scope_region_id, $region->id)) {
            throw ValidationException::withMessages(['region' => 'Wilayah rekap tidak termasuk scope event.']);
        }
        abort_unless($scope->canAccessRegion($request->user(), $election, $region), 404);
    }

    private function summary(RecapComparison $comparison, ?array $currentSnapshot = null): array
    {
        $summary = [
            'id' => $comparison->id,
            'version' => $comparison->version,
            'has_discrepancy' => $comparison->has_discrepancy,
            'official' => [
                'valid_votes' => $comparison->official_valid_votes,
                'invalid_votes' => $comparison->official_invalid_votes,
                'total_votes' => $comparison->official_total_votes,
            ],
            'automatic' => [
                'valid_votes' => $comparison->automatic_valid_votes,
                'invalid_votes' => $comparison->automatic_invalid_votes,
                'total_votes' => $comparison->automatic_total_votes,
            ],
            'items' => $comparison->relationLoaded('items')
                ? $comparison->items->map(fn ($item) => [
                    'participant_id' => $item->contest_participant_id,
                    'official_votes' => $item->official_votes,
                    'automatic_votes' => $item->automatic_votes,
                    'delta' => $item->delta,
                ])->values()
                : [],
        ];

        if ($currentSnapshot !== null) {
            $currentVotes = $currentSnapshot['items']->keyBy('participant_id');
            $itemDiscrepancy = $comparison->items->contains(function ($item) use ($currentVotes): bool {
                $current = $currentVotes->get($item->contest_participant_id);

                return $current === null || (int) $item->official_votes !== (int) $current['automatic_votes'];
            });
            $summary['current_has_discrepancy'] = $itemDiscrepancy
                || (int) $comparison->official_invalid_votes !== $currentSnapshot['invalid_votes']
                || (int) $comparison->official_total_votes !== $currentSnapshot['total_votes'];
            $summary['current_delta_total'] = (int) $comparison->official_total_votes - $currentSnapshot['total_votes'];
            $summary['snapshot_stale'] = (int) $comparison->snapshot_verified_tps_count !== $currentSnapshot['verified_tps_count']
                || (int) $comparison->automatic_total_votes !== $currentSnapshot['total_votes'];
        }

        return $summary;
    }
}
