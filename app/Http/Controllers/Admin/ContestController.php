<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\ContestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreContestRequest;
use App\Models\Election;
use App\Models\ElectionContest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContestController extends Controller
{
    public function store(StoreContestRequest $request, Election $election, WriteAuditLog $audit): RedirectResponse
    {
        $contest = $election->contests()->create([...$request->validated(), 'status' => ContestStatus::Draft]);
        $audit->handle($request->user(), 'contest.created', $contest, after: $contest->toArray());

        return back()->with('success', 'Kontes ditambahkan sebagai draft.');
    }

    public function update(Request $request, ElectionContest $contest, WriteAuditLog $audit): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(ContestStatus::class)],
            'ballot_schema_version' => ['nullable', 'string', 'max:64'],
        ]);
        $before = $contest->toArray();
        $contest->update($data);
        $audit->handle($request->user(), 'contest.updated', $contest, before: $before, after: $contest->fresh()->toArray());

        return back()->with('success', 'Konfigurasi kontes berhasil diperbarui.');
    }
}
