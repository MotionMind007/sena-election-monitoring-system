<?php

namespace Tests\Feature;

use App\Enums\RegionLevel;
use App\Enums\UserRole;
use App\Models\PollingStation;
use App\Models\Region;
use App\Models\User;
use Database\Seeders\PapuaRegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Kpu2024SyntheticPollingStationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_idempotent_synthetic_tps_with_kpu_2024_totals_for_each_papua_regency(): void
    {
        $this->seed(PapuaRegionSeeder::class);
        User::factory()->role(UserRole::SuperAdmin)->create();
        $targets = ['9403' => 568, '9408' => 370, '9409' => 484, '9419' => 149, '9420' => 216, '9426' => 156, '9427' => 79, '9428' => 147, '9471' => 940];
        foreach (array_keys($targets) as $code) {
            $regency = Region::query()->where('code', $code)->firstOrFail();
            $district = Region::query()->create(['code' => "{$code}99", 'name' => "Distrik {$code}", 'level' => RegionLevel::District, 'parent_id' => $regency->id, 'source' => 'fixture']);
            Region::query()->create(['code' => "{$code}99001", 'name' => "Kampung {$code}", 'level' => RegionLevel::Village, 'parent_id' => $district->id, 'source' => 'fixture']);
        }
        $this->artisan('geography:seed-kpu-2024-tps', ['--confirm' => true])->assertSuccessful();
        $this->assertSame(3109, PollingStation::query()->where('source', 'synthetic-kpu-2024')->count());
        foreach ($targets as $code => $target) {
            $region = Region::query()->where('code', $code)->firstOrFail();
            $districtIds = Region::query()->where('parent_id', $region->id)->pluck('id');
            $villageIds = Region::query()->whereIn('parent_id', $districtIds)->pluck('id');
            $this->assertSame($target, PollingStation::query()->whereIn('region_id', $villageIds)->count());
        }
        $this->artisan('geography:seed-kpu-2024-tps', ['--confirm' => true])->assertSuccessful();
        $this->assertSame(3109, PollingStation::query()->where('source', 'synthetic-kpu-2024')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'polling_stations.synthetic_kpu_2024_seeded']);
    }

    public function test_it_preserves_an_existing_master_tps_and_counts_it_toward_the_kpu_target(): void
    {
        $this->seed(PapuaRegionSeeder::class);
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $targets = ['9403' => 568, '9408' => 370, '9409' => 484, '9419' => 149, '9420' => 216, '9426' => 156, '9427' => 79, '9428' => 147, '9471' => 940];
        $firstVillage = null;
        foreach (array_keys($targets) as $code) {
            $regency = Region::query()->where('code', $code)->firstOrFail();
            $district = Region::query()->create(['code' => "{$code}98", 'name' => "Distrik {$code}", 'level' => RegionLevel::District, 'parent_id' => $regency->id, 'source' => 'fixture']);
            $village = Region::query()->create(['code' => "{$code}98001", 'name' => "Kampung {$code}", 'level' => RegionLevel::Village, 'parent_id' => $district->id, 'source' => 'fixture']);
            $firstVillage ??= $village;
        }
        PollingStation::query()->create(['region_id' => $firstVillage->id, 'code' => 'MANUAL-EXISTING-TPS', 'number' => 1, 'location_label' => 'TPS 001', 'source' => 'manual', 'created_by' => $admin->id]);

        $this->artisan('geography:seed-kpu-2024-tps', ['--confirm' => true])->assertSuccessful();

        $this->assertDatabaseHas('polling_stations', ['code' => 'MANUAL-EXISTING-TPS', 'source' => 'manual']);
        $this->assertSame(3109, PollingStation::query()->count());
        $this->assertSame(3108, PollingStation::query()->where('source', 'synthetic-kpu-2024')->count());
    }
}
