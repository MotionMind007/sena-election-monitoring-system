<?php

namespace App\Actions\Geography;

use App\Actions\Audit\WriteAuditLog;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateManualRegion
{
    public function __construct(private readonly WriteAuditLog $audit) {}

    public function handle(User $actor, Region $region, string $name): Region
    {
        return DB::transaction(function () use ($actor, $region, $name): Region {
            $before = $region->toArray();
            $region->update(['name' => $name]);
            $this->audit->handle($actor, 'region.updated', $region, before: $before, after: $region->toArray());

            return $region->refresh();
        });
    }
}
