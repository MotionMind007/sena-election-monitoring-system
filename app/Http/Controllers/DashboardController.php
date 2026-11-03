<?php

namespace App\Http\Controllers;

use App\Enums\RegionLevel;
use App\Enums\UserRole;
use App\Models\Election;
use App\Models\ElectionPollingStation;
use App\Models\Region;
use App\Services\DashboardReadModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardReadModel $readModel) {}

    public function __invoke(Request $request): Response
    {
        $page = match (true) {
            $request->routeIs('map.index') => 'Map/Index',
            $request->routeIs('monitoring.index') => 'Monitoring/Index',
            default => 'Dashboard',
        };
        $user = $request->user();
        $eventQuery = Election::query()->with(['contests.participants.electionParty.party', 'contests.participants.electoralDistrict', 'scopeRegion:id,parent_id,name,level']);
        if (! $user->hasRole(UserRole::SuperAdmin)) {
            $eventQuery->whereHas('eventAssignments', fn ($query) => $query->where('user_id', $user->id));
        }
        $events = $eventQuery->orderByDesc('year')->orderBy('id')->get();
        $requestedEventId = $request->integer('event');
        $event = $requestedEventId > 0 ? $events->firstWhere('id', $requestedEventId) : $events->first();
        if ($requestedEventId > 0 && ! $event) {
            abort(404);
        }
        if (! $event) {
            return Inertia::render($page, $this->emptyState());
        }

        $requestedContestId = $request->integer('contest');
        $contest = $requestedContestId > 0 ? $event->contests->firstWhere('id', $requestedContestId) : $event->contests->first();
        if ($requestedContestId > 0 && ! $contest) {
            abort(404);
        }
        if (! $contest) {
            return Inertia::render($page, $this->emptyState($events, $event));
        }

        $tpsQuery = ElectionPollingStation::query()->where('election_id', $event->id)->where('eligible', true)->with([
            'assignments:id,election_polling_station_id,user_id',
            'pollingStation:id,region_id,code,number,location_label',
            'pollingStation.region:id,parent_id,name,level',
            'pollingStation.region.parent:id,parent_id,name,level',
            'pollingStation.region.parent.parent:id,parent_id,name,level',
            'pollingStation.region.parent.parent.parent:id,parent_id,name,level',
        ]);
        if ($user->hasRole(UserRole::Witness)) {
            $tpsQuery->whereHas('assignments', fn ($query) => $query->where('user_id', $user->id));
        } elseif ($user->hasRole(UserRole::Coordinator)) {
            $tpsQuery->whereHas('pollingStation', fn ($query) => $query->whereIn('region_id', $this->assignedRegionTree($user->id, $event->id)));
        }
        $accessibleTps = $tpsQuery->orderBy('id')->get();

        $scope = $event->scopeRegion;
        $requestedRegionId = $request->integer('region');
        if ($requestedRegionId > 0) {
            $scope = Region::query()->whereKey($requestedRegionId)
                ->whereHas('electionRegions', fn ($query) => $query->where('election_id', $event->id))->firstOrFail();
            if (! in_array($scope->id, $this->descendantRegionIds($event->scope_region_id), true)) {
                abort(404);
            }
        }
        $scopeRegionIds = $this->descendantRegionIds($scope->id);
        $eventTps = $accessibleTps->filter(fn (ElectionPollingStation $station) => in_array($station->pollingStation->region_id, $scopeRegionIds, true))->values();
        if ($requestedRegionId > 0 && ! $user->hasRole(UserRole::SuperAdmin) && $eventTps->isEmpty()) {
            abort(403);
        }

        $assigned = $eventTps->filter(fn (ElectionPollingStation $station) => $station->assignments->isNotEmpty())->count();
        $cacheTtl = (int) config('performance.dashboard_cache_ttl_seconds', 0);
        $cacheKey = implode(':', [
            'dashboard', 'v1', 'user', $user->id, 'event', $event->id, 'contest', $contest->id,
            'scope', $scope->id, 'tps', hash('xxh128', $eventTps->pluck('id')->join(',')),
        ]);
        $buildResult = fn (): array => $this->readModel->build($event, $contest, $eventTps, $scope);
        $resultData = $cacheTtl > 0
            ? Cache::remember($cacheKey, now()->addSeconds($cacheTtl), $buildResult)
            : $buildResult();
        if ($page === 'Dashboard') {
            $resultData['monitoring'] = array_slice($resultData['monitoring'], 0, 25);
        }
        $regionFilters = $this->regionFilters($event, $accessibleTps, $scope, $user->hasRole(UserRole::SuperAdmin));

        return Inertia::render($page, [
            'event' => [
                'id' => $event->id, 'name' => $event->name, 'year' => $event->year,
                'status' => $event->status->value, 'contest' => $contest->name,
                'scope' => ['id' => $scope->id, 'name' => $scope->name, 'level' => $scope->level->value],
            ],
            'eventOptions' => $events->map(fn (Election $option) => ['id' => $option->id, 'name' => $option->name, 'year' => $option->year, 'status' => $option->status->value])->values(),
            'contestOptions' => $event->contests->map(fn ($option) => ['id' => $option->id, 'name' => $option->name, 'status' => $option->status->value])->values(),
            'selectedContestId' => $contest->id,
            'breadcrumbs' => $this->breadcrumbs($scope, $event->scope_region_id),
            'regionFilters' => $regionFilters,
            'stats' => [...$resultData['stats'], 'assigned' => $assigned],
            'coverage' => [], 'monitoring' => [], 'activities' => [],
            'demoPreview' => $resultData,
        ]);
    }

    private function assignedRegionTree(int $userId, int $electionId): array
    {
        $ids = Region::query()->whereIn('id', function ($query) use ($userId, $electionId): void {
            $query->select('region_id')->from('region_assignments')->where('user_id', $userId)->where('election_id', $electionId);
        })->pluck('id')->all();

        return $this->expandRegionIds($ids);
    }

    private function descendantRegionIds(int $rootId): array
    {
        return $this->expandRegionIds([$rootId]);
    }

    private function expandRegionIds(array $ids): array
    {
        $frontier = $ids;
        while ($frontier !== []) {
            $children = Region::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$children];
            $frontier = $children;
        }

        return array_values(array_unique($ids));
    }

    private function breadcrumbs(Region $scope, int $rootId): array
    {
        $items = [];
        $current = $scope;
        while ($current) {
            array_unshift($items, ['id' => $current->id, 'name' => $current->name, 'level' => $current->level->value]);
            if ($current->id === $rootId) {
                break;
            }
            $current = $current->parent()->first();
        }

        return $items;
    }

    private function regionFilters(Election $event, $accessibleTps, Region $scope, bool $isSuperAdmin): array
    {
        $root = $event->scopeRegion;
        $allowedIds = $isSuperAdmin
            ? $event->regionSnapshots()->pluck('region_id')->all()
            : $this->accessibleRegionIds($accessibleTps, $root->id);
        $path = collect($this->breadcrumbs($scope, $root->id));
        $selectedRegency = $path->first(fn (array $item) => in_array($item['level'], [RegionLevel::Regency->value, RegionLevel::City->value], true));
        $selectedDistrict = $path->firstWhere('level', RegionLevel::District->value);
        $selectedVillage = $path->firstWhere('level', RegionLevel::Village->value);

        $regencies = in_array($root->level, [RegionLevel::Regency, RegionLevel::City], true)
            ? collect([['id' => $root->id, 'name' => $root->name, 'level' => $root->level->value]])
            : $this->regionOptions($allowedIds, $root->id, [RegionLevel::Regency, RegionLevel::City]);
        $districts = $selectedRegency
            ? $this->regionOptions($allowedIds, $selectedRegency['id'], [RegionLevel::District])
            : collect();
        $villages = $selectedDistrict
            ? $this->regionOptions($allowedIds, $selectedDistrict['id'], [RegionLevel::Village])
            : collect();

        return [
            'root' => ['id' => $root->id, 'name' => $root->name, 'level' => $root->level->value],
            'selected' => [
                'regency_id' => $selectedRegency['id'] ?? null,
                'district_id' => $selectedDistrict['id'] ?? null,
                'village_id' => $selectedVillage['id'] ?? null,
            ],
            'regencies' => $regencies->values(),
            'districts' => $districts->values(),
            'villages' => $villages->values(),
        ];
    }

    private function accessibleRegionIds($accessibleTps, int $rootId): array
    {
        $ids = [$rootId];
        foreach ($accessibleTps as $eventStation) {
            $region = $eventStation->pollingStation->region;
            while ($region) {
                $ids[] = $region->id;
                if ($region->id === $rootId) {
                    break;
                }
                $region = $region->parent;
            }
        }

        return array_values(array_unique($ids));
    }

    private function regionOptions(array $allowedIds, int $parentId, array $levels)
    {
        return Region::query()
            ->whereIn('id', $allowedIds)
            ->where('parent_id', $parentId)
            ->whereIn('level', array_map(fn (RegionLevel $level) => $level->value, $levels))
            ->orderBy('name')
            ->get(['id', 'name', 'level'])
            ->map(fn (Region $region) => ['id' => $region->id, 'name' => $region->name, 'level' => $region->level->value]);
    }

    private function emptyState($events = null, ?Election $event = null): array
    {
        return [
            'event' => $event ? ['id' => $event->id, 'name' => $event->name, 'year' => $event->year, 'status' => $event->status->value, 'contest' => null, 'scope' => ['id' => $event->scopeRegion->id, 'name' => $event->scopeRegion->name, 'level' => $event->scopeRegion->level->value]] : null,
            'eventOptions' => collect($events)->map(fn (Election $option) => ['id' => $option->id, 'name' => $option->name, 'year' => $option->year, 'status' => $option->status->value])->values(),
            'contestOptions' => [], 'selectedContestId' => null, 'breadcrumbs' => [],
            'regionFilters' => ['root' => null, 'selected' => ['regency_id' => null, 'district_id' => null, 'village_id' => null], 'regencies' => [], 'districts' => [], 'villages' => []],
            'stats' => ['total' => 0, 'submitted' => 0, 'verified' => 0, 'review' => 0, 'revision' => 0, 'unreported' => 0, 'assigned' => 0],
            'coverage' => [], 'monitoring' => [], 'activities' => [], 'demoPreview' => null,
        ];
    }
}
