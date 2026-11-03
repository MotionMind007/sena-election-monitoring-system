<?php

namespace App\Actions\Elections;

use App\Actions\Audit\WriteAuditLog;
use App\Models\Election;
use App\Models\ElectionPollingStation;
use App\Models\PollingStation;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePollingStation
{
    public function __construct(private readonly WriteAuditLog $audit) {}

    public function handle(User $actor, Region $village, string $name): PollingStation
    {
        return DB::transaction(function () use ($actor, $village, $name): PollingStation {
            $number = ((int) PollingStation::query()
                ->where('region_id', $village->id)
                ->lockForUpdate()
                ->max('number')) + 1;

            $station = PollingStation::query()->create([
                'region_id' => $village->id,
                'code' => 'TPS-'.Str::upper(Str::random(16)),
                'number' => $number,
                'location_label' => $name,
                'source' => 'manual',
                'created_by' => $actor->id,
            ]);

            Election::query()
                ->whereIn('status', ['draft', 'preparing'])
                ->whereHas('regionSnapshots', fn ($query) => $query->where('region_id', $village->id))
                ->each(fn (Election $election) => ElectionPollingStation::query()->firstOrCreate(
                    ['election_id' => $election->id, 'polling_station_id' => $station->id],
                    ['eligible' => true, 'status' => 'active'],
                ));

            $this->audit->handle($actor, 'polling_station.created_manually', $station, after: $station->toArray());

            return $station;
        });
    }
}
