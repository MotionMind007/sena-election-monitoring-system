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
        $data = $this->validated($request, $election);
        $regionIds = $this->validateCoverage($election, $data['region_ids']);

        $district = DB::transaction(function () use ($election, $data, $regionIds): ElectoralDistrict {
            $district = $election->electoralDistricts()->create(['name' => $data['name'], 'active' => true]);
            $district->regions()->sync($regionIds);

            return $district;
        });
        $audit->handle($request->user(), 'electoral_district.created', $district, after: $district->load('regions')->toArray());

        return back()->with('success', 'Dapil dan cakupan wilayahnya berhasil ditambahkan.');
    }

    public function update(Request $request, ElectoralDistrict $electoralDistrict, WriteAuditLog $audit): RedirectResponse
    {
        $election = $electoralDistrict->election;
        abort_unless($election->type === 'pileg', 404);
        $data = $this->validated($request, $election, $electoralDistrict);
        $regionIds = $this->validateCoverage($election, $data['region_ids'], $electoralDistrict);
        if (DB::table('vote_submissions')->where('election_id', $election->id)->exists()) {
            throw ValidationException::withMessages(['region_ids' => 'Cakupan dapil tidak dapat diubah setelah event memiliki laporan suara.']);
        }

        $before = $electoralDistrict->load('regions')->toArray();
        DB::transaction(function () use ($electoralDistrict, $data, $regionIds): void {
            $electoralDistrict->update(['name' => $data['name']]);
            $electoralDistrict->regions()->sync($regionIds);
        });
        $audit->handle($request->user(), 'electoral_district.updated', $electoralDistrict, before: $before, after: $electoralDistrict->fresh()->load('regions')->toArray());

        return back()->with('success', 'Dapil berhasil diperbarui.');
    }

    public function destroy(Request $request, ElectoralDistrict $electoralDistrict, WriteAuditLog $audit): RedirectResponse
    {
        if ($electoralDistrict->candidates()->exists() || $electoralDistrict->electionParties()->exists()) {
            throw ValidationException::withMessages(['dapil' => 'Dapil tidak dapat dihapus karena sudah dipakai partai peserta atau caleg. Hapus konfigurasi terkait terlebih dahulu.']);
        }
        $before = $electoralDistrict->load('regions')->toArray();
        DB::transaction(fn () => $electoralDistrict->delete());
        $audit->handle($request->user(), 'electoral_district.deleted', $electoralDistrict, before: $before);

        return back()->with('success', 'Dapil berhasil dihapus.');
    }

    private function validated(Request $request, Election $election, ?ElectoralDistrict $ignore = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('electoral_districts')->where('election_id', $election->id)->ignore($ignore)],
            'region_ids' => ['required', 'array', 'min:1'],
            'region_ids.*' => ['required', 'integer', 'distinct', Rule::exists('regions', 'id')->whereIn('level', ['district', 'village'])],
        ]);
    }

    /** @param array<int, int|string> $submittedRegionIds
     *  @return array<int, int>
     */
    private function validateCoverage(Election $election, array $submittedRegionIds, ?ElectoralDistrict $ignore = null): array
    {
        $regionIds = collect($submittedRegionIds)->map(fn ($id) => (int) $id)->values();
        $regions = Region::query()->whereIn('id', $regionIds)->with('parent:id,parent_id,level')->get();
        if ($regions->count() !== $regionIds->count() || $election->regionSnapshots()->whereIn('region_id', $regionIds)->count() !== $regionIds->count()) {
            throw ValidationException::withMessages(['region_ids' => 'Semua wilayah cakupan harus berada di dalam snapshot event.']);
        }

        $coverage = $this->villageCoverage($regions);
        if ($coverage->count() < 1) {
            throw ValidationException::withMessages(['region_ids' => 'Cakupan dapil tidak valid.']);
        }
        $otherDapils = $election->electoralDistricts()->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))->with('regions')->get();
        foreach ($otherDapils as $dapil) {
            if ($coverage->intersect($this->villageCoverage($dapil->regions))->isNotEmpty()) {
                throw ValidationException::withMessages(['region_ids' => "Cakupan tumpang tindih dengan {$dapil->name}. Pilih distrik atau kampung/kelurahan yang belum dipakai dapil lain."]);
            }
        }

        return $regionIds->all();
    }

    /** @param \Illuminate\Support\Collection<int, Region> $regions */
    private function villageCoverage($regions)
    {
        $villageIds = collect();
        foreach ($regions as $region) {
            if ($region->level->value === 'village') {
                $villageIds->push($region->id);
            } else {
                $villageIds->push(...Region::query()->where('parent_id', $region->id)->where('level', 'village')->pluck('id')->all());
            }
        }
        return $villageIds->unique()->values();
    }
}
