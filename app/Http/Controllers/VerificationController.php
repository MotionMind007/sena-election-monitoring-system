<?php

namespace App\Http\Controllers;

use App\Actions\Verification\DecideSubmission;
use App\Actions\Verification\ReviewSubmission;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\VoteSubmission;
use App\Services\RegionHierarchy;
use App\Services\ScopeAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VerificationController extends Controller
{
    public function index(Request $request, RegionHierarchy $hierarchy): Response
    {
        abort_unless($request->user()->hasAnyRole(UserRole::SuperAdmin, UserRole::Coordinator), 403);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:submitted,recommended,revision_requested,rejected,approved,conflict'],
        ]);
        $query = VoteSubmission::query()
            ->with(['election:id,name,year', 'contest:id,name', 'eventTps.pollingStation.region:id,name', 'submitter:id,name', 'totals'])
            ->whereNot('status', SubmissionStatus::Draft->value);

        if ($request->user()->hasRole(UserRole::Coordinator)) {
            $this->applyCoordinatorScope($query, $request->user(), $hierarchy);
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['q'] ?? null)) {
            $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($filters['q'])).'%';
            $query->where(function (Builder $search) use ($needle): void {
                $search->whereHas('eventTps.pollingStation', fn (Builder $station) => $station
                    ->where('code', 'like', $needle)
                    ->orWhere('location_label', 'like', $needle)
                    ->orWhereHas('region', fn (Builder $region) => $region->where('name', 'like', $needle)))
                    ->orWhereHas('submitter', fn (Builder $submitter) => $submitter->where('name', 'like', $needle));
            });
        }

        $items = $query->latest('id')->paginate(30)->withQueryString()
            ->through(fn (VoteSubmission $item) => [
                'id' => $item->id,
                'event' => $item->election->name,
                'contest' => $item->contest->name,
                'tps' => $item->eventTps->pollingStation->code,
                'area' => $item->eventTps->pollingStation->region->name,
                'submitter' => $item->submitter->name,
                'status' => $item->status->value,
                'version' => $item->version,
                'entry_mode' => $item->entry_mode,
                'total' => $item->totals->total_votes,
                'own' => $item->submitter_id === $request->user()->id,
            ]);

        return Inertia::render('Verification/Index', [
            'submissions' => $items,
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''],
            'role' => $request->user()->role->value,
        ]);
    }

    private function applyCoordinatorScope(Builder $query, User $user, RegionHierarchy $hierarchy): void
    {
        $eventIds = $user->eventAssignments()->pluck('election_id');
        $assignments = $user->regionAssignments()->whereIn('election_id', $eventIds)->get(['election_id', 'region_id'])->groupBy('election_id');
        if ($assignments->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $scope) use ($assignments, $hierarchy): void {
            foreach ($assignments as $eventId => $eventAssignments) {
                $regionIds = $eventAssignments->flatMap(fn ($assignment) => $hierarchy->descendantIds($assignment->region_id))->unique()->values()->all();
                $scope->orWhere(function (Builder $eventScope) use ($eventId, $regionIds): void {
                    $eventScope->where('election_id', $eventId)
                        ->whereHas('eventTps.pollingStation', fn (Builder $station) => $station->whereIn('region_id', $regionIds));
                });
            }
        });
    }

    public function review(Request $request, VoteSubmission $submission, ScopeAccess $scope, ReviewSubmission $action): RedirectResponse
    {
        abort_unless($scope->canAccessPollingStation($request->user(), $submission->eventTps()->with(['election', 'pollingStation.region.parent'])->firstOrFail()), 404);
        $data = $request->validate(['action' => ['required', 'in:recommend,revision_requested'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $action->handle($request->user(), $submission, $data['action'], $data['notes'] ?? null);

        return back()->with('success', 'Review koordinator tersimpan.');
    }

    public function decide(Request $request, VoteSubmission $submission, DecideSubmission $action): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:approve,reject,revision_requested'], 'reason' => ['nullable', 'required_unless:action,approve', 'string', 'max:1000']]);
        $action->handle($request->user(), $submission, $data['action'], $data['reason'] ?? null);

        return back()->with('success', 'Keputusan Super Admin tersimpan.');
    }
}
