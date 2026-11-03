<?php

namespace App\Actions\Geography;

use App\Actions\Audit\WriteAuditLog;
use App\Models\Election;
use App\Models\ElectionRegion;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateManualRegion
{
    public function __construct(private readonly WriteAuditLog $audit) {}

    public function handle(User $actor, array $data): Region
    {
        return DB::transaction(function () use ($actor, $data): Region {
            $region = Region::query()->create([
                'code' => 'MAN-'.Str::upper(Str::random(12)),
                'name' => $data['name'],
                'level' => $data['level'],
                'parent_id' => $data['parent_id'] ?? null,
                'source' => 'manual',
                'source_version' => 'Input manual',
            ]);

            $this->syncPreparingElectionSnapshots($region);
            $this->audit->handle($actor, 'region.created_manually', $region, after: $region->toArray());

            return $region;
        });
    }

    private function syncPreparingElectionSnapshots(Region $region): void
    {
        $ancestorIds = collect([$region->id]);
        $parent = $region->parent;

        while ($parent) {
            $ancestorIds->push($parent->id);
            $parent = $parent->parent;
        }

        Election::query()
            ->whereIn('status', ['draft', 'preparing'])
            ->whereIn('scope_region_id', $ancestorIds)
            ->each(function (Election $election) use ($region): void {
                ElectionRegion::query()->firstOrCreate(
                    ['election_id' => $election->id, 'region_id' => $region->id],
                    [
                        'code_snapshot' => $region->code,
                        'name_snapshot' => $region->name,
                        'level_snapshot' => $region->level->value,
                        'source_snapshot' => $region->source,
                        'source_version_snapshot' => $region->source_version,
                    ],
                );
            });
    }
}
