<?php

namespace App\Actions\Elections;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\RegionLevel;
use App\Models\Election;
use App\Models\ElectionPollingStation;
use App\Models\PollingStation;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProvisionPollingStations
{
    public function __construct(private readonly WriteAuditLog $audit) {}

    public function handle(User $actor, Election $election, Region $region, int $count): int
    {
        if ($region->level !== RegionLevel::Village) {
            throw ValidationException::withMessages(['region_id' => 'TPS hanya dapat dibuat pada desa/kelurahan.']);
        }

        if (! $election->regionSnapshots()->where('region_id', $region->id)->exists()) {
            throw ValidationException::withMessages(['region_id' => 'Wilayah tidak termasuk snapshot event.']);
        }

        return DB::transaction(function () use ($actor, $election, $region, $count): int {
            $created = 0;

            for ($number = 1; $number <= $count; $number++) {
                $station = PollingStation::query()->firstOrCreate(
                    ['region_id' => $region->id, 'number' => $number],
                    ['code' => sprintf('%s-TPS-%03d', $region->code, $number), 'source' => 'admin', 'created_by' => $actor->id],
                );

                ElectionPollingStation::query()->firstOrCreate(
                    ['election_id' => $election->id, 'polling_station_id' => $station->id],
                    ['eligible' => true, 'status' => 'active'],
                );

                $created += $station->wasRecentlyCreated ? 1 : 0;
            }

            $this->audit->handle($actor, 'polling_stations.provisioned', $election, metadata: [
                'region_id' => $region->id,
                'requested_count' => $count,
                'created_count' => $created,
            ]);

            return $created;
        });
    }
}
