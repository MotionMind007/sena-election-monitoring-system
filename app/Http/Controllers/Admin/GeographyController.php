<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Geography\CreateManualRegion;
use App\Actions\Geography\DeleteManualRegion;
use App\Actions\Geography\UpdateManualRegion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRegionRequest;
use App\Http\Requests\Admin\UpdateRegionRequest;
use App\Models\GeographyImport;
use App\Models\PollingStation;
use App\Models\Region;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GeographyController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'level' => ['nullable', Rule::in(['province', 'regency', 'city', 'district', 'village'])],
            'regency_id' => ['nullable', 'integer', Rule::exists('regions', 'id')->where(fn ($query) => $query->whereIn('level', ['regency', 'city']))],
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        // A stale source filter must not hide a whole level. Demo rows, for
        // example, only exist at district/village level, not regency level.
        if (($filters['source'] ?? null) && ($filters['level'] ?? null)) {
            $sourceExistsAtLevel = Region::query()
                ->where('source', $filters['source'])
                ->where('level', $filters['level'])
                ->exists();

            if (! $sourceExistsAtLevel) {
                $filters['source'] = null;
            }
        }

        $regions = Region::query()->with('parent:id,name')->withCount(['children', 'pollingStations']);

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $regions->whereLike('name', "%{$search}%", caseSensitive: false);
        }

        if ($level = $filters['level'] ?? null) {
            $regions->where('level', $level);
        }

        if ($source = $filters['source'] ?? null) {
            $regions->where('source', $source);
        }

        if ($regencyId = $filters['regency_id'] ?? null) {
            $districtIds = Region::query()->where('parent_id', $regencyId)->pluck('id');
            $scopeIds = collect([(int) $regencyId])
                ->concat($districtIds)
                ->concat(Region::query()->whereIn('parent_id', $districtIds)->pluck('id'));
            $regions->whereIn('id', $scopeIds);
        }

        return Inertia::render('Admin/Geography/Index', [
            'regions' => $regions
                ->orderBy('name')
                ->paginate(50, ['id', 'code', 'name', 'level', 'parent_id', 'source', 'source_version'])
                ->onEachSide(1)
                ->withQueryString(),
            'regionsForSelection' => Region::query()->orderBy('name')->get(['id', 'name', 'level', 'parent_id']),
            'pollingStations' => PollingStation::query()->with('region.parent.parent.parent')->latest()->limit(20)->get(['id', 'region_id', 'location_label', 'source', 'created_at']),
            'imports' => GeographyImport::query()->latest()->limit(10)->get(['id', 'source', 'source_version', 'original_name', 'status', 'summary', 'created_at', 'committed_at']),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'level' => $filters['level'] ?? '',
                'regency_id' => $filters['regency_id'] ?? '',
                'source' => $filters['source'] ?? '',
            ],
            'filterOptions' => [
                'regencies' => Region::query()->whereIn('level', ['regency', 'city'])->orderBy('name')->get(['id', 'name']),
                'sources' => Region::query()->whereNotNull('source')->distinct()->orderBy('source')->pluck('source'),
            ],
            'manualRegionOptions' => [
                'regencies' => Region::query()->whereIn('level', ['regency', 'city'])->orderBy('name')->get(['id', 'name', 'level']),
                'districts' => Region::query()->where('level', 'district')->orderBy('name')->get(['id', 'name', 'level']),
            ],
        ]);
    }

    public function store(StoreRegionRequest $request, CreateManualRegion $action): RedirectResponse
    {
        $region = $action->handle($request->user(), $request->validated());

        return back()->with('success', "Wilayah {$region->name} berhasil ditambahkan.");
    }

    public function update(UpdateRegionRequest $request, Region $region, UpdateManualRegion $action): RedirectResponse
    {
        $region = $action->handle($request->user(), $region, $request->string('name')->toString());

        return back()->with('success', "Wilayah {$region->name} berhasil diperbarui.");
    }

    public function destroy(Request $request, Region $region, DeleteManualRegion $action): RedirectResponse
    {
        $action->handle($request->user(), $region);

        return back()->with('success', 'Wilayah berhasil dihapus.');
    }
}
