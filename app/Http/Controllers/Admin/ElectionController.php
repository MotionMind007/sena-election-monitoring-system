<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\WriteAuditLog;
use App\Actions\Elections\CreateElection;
use App\Enums\ElectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreElectionRequest;
use App\Models\Election;
use App\Models\Party;
use App\Models\Region;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ElectionController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Election::class);

        return Inertia::render('Admin/Events/Index', [
            'events' => Election::query()->with([
                'scopeRegion:id,code,name,level',
                'contests.participants.supportingParties:id,name,abbreviation,logo_path',
                'contests.participants.electionParty.party:id,name,abbreviation,logo_path',
                'contests.participants.electoralDistrict:id,name',
                'electoralDistricts.regions:id,name,parent_id',
                'electoralDistricts.electionParties.party:id,name,abbreviation,logo_path',
                'electionParties.party:id,name,abbreviation,logo_path',
                'electionParties.electoralDistricts:id,name',
            ])->latest()->get(),
            'scopeRegions' => Region::query()->whereIn('level', ['province', 'regency', 'city'])->orderBy('code')->get(['id', 'code', 'name', 'level']),
            'districts' => Region::query()->where('level', 'district')->with('parent:id,name,parent_id')->orderBy('name')->get(['id', 'name', 'parent_id']),
            'parties' => Party::query()->orderBy('name')->get(['id', 'name', 'abbreviation', 'logo_path', 'active']),
            'eventStatuses' => collect(ElectionStatus::cases())->map(fn (ElectionStatus $status) => $status->value),
        ]);
    }

    public function store(StoreElectionRequest $request, CreateElection $action): RedirectResponse
    {
        $action->handle($request->user(), $request->validated());

        return to_route('admin.events.index')->with('success', 'Event berhasil dibuat dan wilayahnya telah disnapshot.');
    }

    public function updateStatus(Request $request, Election $election, WriteAuditLog $audit): RedirectResponse
    {
        $this->authorize('update', $election);
        $data = $request->validate(['status' => ['required', Rule::enum(ElectionStatus::class)]]);
        $before = $election->toArray();
        $election->update(['status' => $data['status']]);
        $audit->handle($request->user(), 'election.status_updated', $election, before: $before, after: $election->fresh()->toArray());

        return back()->with('success', 'Status event berhasil diperbarui.');
    }
}
