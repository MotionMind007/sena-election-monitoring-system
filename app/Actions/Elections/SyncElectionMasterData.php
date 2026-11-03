<?php

namespace App\Actions\Elections;

use App\Actions\Audit\WriteAuditLog;
use App\Models\Election;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SyncElectionMasterData
{
    public function __construct(private readonly WriteAuditLog $audit) {}

    /** @return array{regions: int, polling_stations: int} */
    public function handle(User $actor, Election $election): array
    {
        return DB::transaction(function () use ($actor, $election): array {
            $now = now();
            $regions = $this->descendantsIncluding($election->scopeRegion()->firstOrFail());
            $regionRows = $regions->map(fn (Region $region): array => [
                'election_id' => $election->id,
                'region_id' => $region->id,
                'code_snapshot' => $region->code,
                'name_snapshot' => $region->name,
                'level_snapshot' => $region->level->value,
                'source_snapshot' => $region->source,
                'source_version_snapshot' => $region->source_version,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $regionsAdded = 0;
            foreach ($regionRows->chunk(500) as $chunk) {
                $regionsAdded += DB::table('election_regions')->insertOrIgnore($chunk->all());
            }

            $stationIds = DB::table('polling_stations')->whereIn('region_id', $regions->pluck('id'))->pluck('id');
            $stationRows = $stationIds->map(fn (int $stationId): array => [
                'election_id' => $election->id,
                'polling_station_id' => $stationId,
                'eligible' => true,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $stationsAdded = 0;
            foreach ($stationRows->chunk(500) as $chunk) {
                $stationsAdded += DB::table('election_polling_stations')->insertOrIgnore($chunk->all());
            }

            $this->audit->handle($actor, 'election.master_data_synced', $election, metadata: [
                'regions_added' => $regionsAdded,
                'polling_stations_added' => $stationsAdded,
            ]);

            return ['regions' => $regionsAdded, 'polling_stations' => $stationsAdded];
        });
    }

    /** @return Collection<int, Region> */
    private function descendantsIncluding(Region $root): Collection
    {
        $regions = collect([$root]);
        $parentIds = [$root->id];
        while ($parentIds !== []) {
            $children = Region::query()->whereIn('parent_id', $parentIds)->get();
            $regions = $regions->concat($children);
            $parentIds = $children->pluck('id')->all();
        }
        return $regions;
    }
}
