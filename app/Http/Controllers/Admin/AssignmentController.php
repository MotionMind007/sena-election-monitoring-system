<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAssignmentRequest;
use App\Http\Requests\Admin\StoreInternalUserRequest;
use App\Models\Election;
use App\Models\ElectionPollingStation;
use App\Models\EventAssignment;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\TpsAssignment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AssignmentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Assignments/Index', [
            'users' => User::query()->whereIn('role', [UserRole::Coordinator, UserRole::Witness])->orderBy('name')->get(['id', 'name', 'email', 'role']),
            'events' => Election::query()->orderByDesc('year')->get(['id', 'name', 'year']),
            'regions' => Region::query()->whereIn('level', ['regency', 'city', 'district', 'village'])->orderBy('name')->get(['id', 'code', 'name', 'level']),
            'eventTps' => ElectionPollingStation::query()->with(['pollingStation:id,region_id,code,number', 'pollingStation.region:id,name'])->get(),
            'assignments' => EventAssignment::query()->with(['user:id,name,email', 'election:id,name,year'])->latest()->get(),
        ]);
    }

    public function store(StoreAssignmentRequest $request, WriteAuditLog $audit): RedirectResponse
    {
        $data = $request->validated();
        $target = User::query()->findOrFail($data['user_id']);
        $election = Election::query()->findOrFail($data['election_id']);

        if ($target->hasRole(UserRole::Coordinator) && $data['scope_type'] !== 'region') {
            throw ValidationException::withMessages(['scope_type' => 'Koordinator wajib ditugaskan ke wilayah.']);
        }

        if ($target->hasRole(UserRole::Witness) && $data['scope_type'] !== 'tps') {
            throw ValidationException::withMessages(['scope_type' => 'Saksi wajib ditugaskan ke TPS.']);
        }

        DB::transaction(function () use ($request, $audit, $data, $target, $election): void {
            $eventAssignment = EventAssignment::query()->updateOrCreate(
                ['user_id' => $target->id, 'election_id' => $election->id],
                ['role' => $target->role, 'assigned_by' => $request->user()->id],
            );

            if ($data['scope_type'] === 'region') {
                if (! $election->regionSnapshots()->where('region_id', $data['region_id'])->exists()) {
                    throw ValidationException::withMessages(['region_id' => 'Wilayah tidak termasuk event.']);
                }

                RegionAssignment::query()->firstOrCreate(
                    ['user_id' => $target->id, 'election_id' => $election->id, 'region_id' => $data['region_id']],
                    ['assigned_by' => $request->user()->id],
                );
            }

            if ($data['scope_type'] === 'tps') {
                $eventTps = ElectionPollingStation::query()->findOrFail($data['election_polling_station_id']);

                if ($eventTps->election_id !== $election->id) {
                    throw ValidationException::withMessages(['election_polling_station_id' => 'TPS tidak termasuk event.']);
                }

                TpsAssignment::query()->firstOrCreate(
                    ['user_id' => $target->id, 'election_id' => $election->id, 'election_polling_station_id' => $eventTps->id],
                    ['assigned_by' => $request->user()->id],
                );
            }

            $audit->handle($request->user(), 'assignment.created', $eventAssignment, after: $eventAssignment->toArray(), metadata: ['scope_type' => $data['scope_type']]);
        });

        return back()->with('success', 'Penugasan tersimpan.');
    }

    public function storeUser(StoreInternalUserRequest $request, WriteAuditLog $audit): RedirectResponse
    {
        $user = User::query()->create([...$request->validated(), 'status' => 'active', 'email_verified_at' => now()]);
        $audit->handle($request->user(), 'user.created', $user, after: ['id' => $user->id, 'email' => $user->email, 'role' => $user->role->value]);

        return back()->with('success', 'Akun internal berhasil dibuat.');
    }
}
