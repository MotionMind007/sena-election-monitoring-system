<?php

namespace App\Actions\Geography;

use App\Actions\Audit\WriteAuditLog;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteManualRegion
{
    public function __construct(private readonly WriteAuditLog $audit) {}

    public function handle(User $actor, Region $region): void
    {
        if ($region->children()->exists() || $region->pollingStations()->exists() || $region->electionRegions()->exists() || $region->electoralDistricts()->exists()) {
            throw ValidationException::withMessages(['region' => 'Wilayah tidak dapat dihapus karena masih memiliki turunan, TPS, atau sudah dipakai konfigurasi event.']);
        }

        DB::transaction(function () use ($actor, $region): void {
            $before = $region->toArray();
            $this->audit->handle($actor, 'region.deleted', $region, before: $before);
            $region->delete();
        });
    }
}
