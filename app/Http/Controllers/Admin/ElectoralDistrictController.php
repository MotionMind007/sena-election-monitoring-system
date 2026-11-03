<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\WriteAuditLog;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\ElectoralDistrict;
use App\Models\Region;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ElectoralDistrictController extends Controller
{
    public function store(Request $request, Election $election, WriteAuditLog $audit): RedirectResponse
    {
        abort_unless($election->type === 'pileg', 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('electoral_districts')->where('election_id', $election->id)],
            'region_ids' => ['required', 'array', 'min:1'],
            'region_ids.*' => ['required', 'integer', 'distinct', Rule::exists('regions', 'id')->where('level', 'district')],
        ]);
        $regionIds = collect($data['region_ids'])->map(fn ($id) => (int) $id)->values();
        $snapshotCount = $election->regionSnapshots()->whereIn('region_id', $regionIds)->where('level_snapshot', 'district')->count();
        if ($snapshotCount !== $regionIds->count()) {
            throw ValidationException::withMessages(['region_ids' => 'Semua distrik harus berada di dalam cakupan event.']);
        }
        $alreadyAssigned = Region::query()->whereIn('id', $regionIds)->whereHas('electoralDistricts', fn ($query) => $query->where('election_id', $election->id))->pluck('name');
        if ($alreadyAssigned->isNotEmpty()) {
            throw ValidationException::withMessages(['region_ids' => 'Distrik sudah dipakai dapil lain: '.$alreadyAssigned->join(', ').'.']);
        }

        $district = DB::transaction(function () use ($election, $data, $regionIds): ElectoralDistrict {
            $district = $election->electoralDistricts()->create(['name' => $data['name'], 'active' => true]);
            $district->regions()->sync($regionIds);

            return $district;
        });
        $audit->handle($request->user(), 'electoral_district.created', $district, after: $district->load('regions')->toArray());

        return back()->with('success', 'Dapil dan cakupan distriknya berhasil ditambahkan.');
    }
}
