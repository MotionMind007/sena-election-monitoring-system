<?php

namespace App\Actions\Elections;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\ContestStatus;
use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Models\ElectionPollingStation;
use App\Models\ElectionRegion;
use App\Models\PollingStation;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateElection
{
    public function __construct(private readonly WriteAuditLog $audit) {}

    public function handle(User $actor, array $data): Election
    {
        return DB::transaction(function () use ($actor, $data): Election {
            $election = Election::query()->create([
                ...Arr::only($data, ['name', 'year', 'type', 'scope_region_id']),
                'status' => ElectionStatus::Draft,
                'created_by' => $actor->id,
            ]);

            $regions = $this->descendantsIncluding(Region::query()->findOrFail($data['scope_region_id']));

            foreach ($regions as $region) {
                ElectionRegion::query()->create([
                    'election_id' => $election->id,
                    'region_id' => $region->id,
                    'code_snapshot' => $region->code,
                    'name_snapshot' => $region->name,
                    'level_snapshot' => $region->level->value,
                    'source_snapshot' => $region->source,
                    'source_version_snapshot' => $region->source_version,
                ]);
            }

            $pollingStations = PollingStation::query()->whereIn('region_id', $regions->pluck('id'))->get();
            foreach ($pollingStations as $pollingStation) {
                ElectionPollingStation::query()->create([
                    'election_id' => $election->id,
                    'polling_station_id' => $pollingStation->id,
                    'eligible' => true,
                    'status' => 'active',
                ]);
            }

            $election->contests()->create([
                'name' => $data['contest_name'] ?? ($data['type'] === 'pileg' ? 'Pemilihan Legislatif' : 'Pemilihan Kepala Daerah'),
                'type' => $data['contest_type'] ?? ($data['type'] === 'pileg' ? 'legislative' : 'executive'),
                'ballot_schema_version' => $data['type'] === 'pileg' ? 'party-candidate-v1' : 'candidate-pair-v1',
                'status' => ContestStatus::Draft,
            ]);

            $this->audit->handle($actor, 'election.created', $election, after: $election->toArray(), metadata: [
                'snapshotted_regions' => $regions->count(),
                'snapshotted_polling_stations' => $pollingStations->count(),
            ]);

            return $election->load(['scopeRegion', 'contests']);
        });
    }

    private function descendantsIncluding(Region $root)
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
