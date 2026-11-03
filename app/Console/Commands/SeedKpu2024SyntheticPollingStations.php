<?php

namespace App\Console\Commands;

use App\Enums\RegionLevel;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\PollingStation;
use App\Models\Region;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedKpu2024SyntheticPollingStations extends Command
{
    private const SOURCE = 'synthetic-kpu-2024';
    private const SOURCE_VERSION = 'KPU Papua 99/2023 - DPT Pemilu 2024';

    /** @var array<string, int> */
    private const TPS_TARGETS = [
        '9403' => 568, '9408' => 370, '9409' => 484, '9419' => 149, '9420' => 216,
        '9426' => 156, '9427' => 79, '9428' => 147, '9471' => 940,
    ];

    protected $signature = 'geography:seed-kpu-2024-tps
        {--confirm : Create synthetic TPS records based on KPU 2024 totals}
        {--dry-run : Validate targets and show the planned distribution without writing data}';
    protected $description = 'Create deterministic synthetic TPS records for Papua using KPU 2024 TPS totals per kabupaten/kota';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing', 'staging'])) {
            $this->error('Data TPS sintetis KPU 2024 tidak boleh dibuat pada environment production.');
            return self::FAILURE;
        }
        if (! $this->option('confirm') && ! $this->option('dry-run')) {
            $this->error('Tambahkan --confirm untuk membuat data, atau --dry-run untuk memeriksa rencana distribusi.');
            return self::FAILURE;
        }

        $actor = User::query()->where('role', UserRole::SuperAdmin->value)->orderBy('id')->first();
        if (! $actor instanceof User) {
            $this->error('Super Admin diperlukan sebagai pembuat data TPS sintetis.');
            return self::FAILURE;
        }

        $plans = $this->plans();
        if ($plans === []) {
            return self::FAILURE;
        }
        $this->table(['Kabupaten/Kota', 'Kampung/Kelurahan', 'TPS lama dipertahankan', 'Target TPS KPU 2024'], array_map(
            fn (array $plan): array => [$plan['region']->name, $plan['villages']->count(), $plan['existingCount'], $plan['target']],
            $plans,
        ));
        if ($this->option('dry-run')) {
            $this->info('Dry-run selesai. Tidak ada TPS yang dibuat.');
            return self::SUCCESS;
        }

        $created = DB::transaction(function () use ($plans, $actor): int {
            $created = 0;
            foreach ($plans as $plan) {
                foreach ($this->distribution($plan) as $villageId => $count) {
                    $village = $plan['villages']->firstWhere('id', $villageId);
                    $existing = $plan['existing']->get($village->id, collect());
                    $nextNumber = ((int) $existing->max('number')) + 1;

                    for ($number = $nextNumber; $number <= $count; $number++) {
                        PollingStation::query()->create([
                            'region_id' => $village->id,
                            'number' => $number,
                            'code' => sprintf('SYN-KPU24-%s-%03d', $village->code, $number),
                            'location_label' => sprintf('TPS %03d', $number),
                            'source' => self::SOURCE,
                            'created_by' => $actor->id,
                        ]);
                        $created++;
                    }
                }
            }
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'polling_stations.synthetic_kpu_2024_seeded',
                'object_type' => Region::class,
                'object_id' => (string) Region::query()->where('code', '94')->value('id'),
                'metadata' => ['synthetic' => true, 'source' => self::SOURCE, 'source_version' => self::SOURCE_VERSION, 'target_tps' => array_sum(self::TPS_TARGETS), 'created_tps' => $created, 'regency_targets' => self::TPS_TARGETS],
            ]);
            return $created;
        });

        $this->info("Selesai: {$created} TPS sintetis dibuat. Target total KPU 2024: ".array_sum(self::TPS_TARGETS).'.');
        return self::SUCCESS;
    }

    /** @return array<int, array{region: Region, villages: \Illuminate\Support\Collection<int, Region>, target: int}> */
    private function plans(): array
    {
        $plans = [];
        foreach (self::TPS_TARGETS as $code => $target) {
            $region = Region::query()->where('code', $code)->first();
            if (! $region instanceof Region) {
                $this->error("Wilayah KPU {$code} belum tersedia di master wilayah.");
                return [];
            }
            $villages = $this->villagesBelow($region);
            if ($villages->isEmpty()) {
                $this->error("{$region->name} belum memiliki kampung/kelurahan untuk distribusi TPS.");
                return [];
            }
            if ($villages->count() > $target) {
                $this->error("{$region->name} memiliki {$villages->count()} kampung/kelurahan, melebihi target {$target} TPS.");
                return [];
            }
            $existing = PollingStation::query()->whereIn('region_id', $villages->pluck('id'))->get()->groupBy('region_id');
            $existingCount = $existing->flatten(1)->count();
            if ($existingCount > $target) {
                $this->error("{$region->name} sudah memiliki {$existingCount} TPS, melebihi target KPU 2024 {$target}. Data tidak diubah.");
                return [];
            }
            $plans[] = compact('region', 'villages', 'target', 'existing', 'existingCount');
        }
        return $plans;
    }

    /** @return \Illuminate\Support\Collection<int, Region> */
    private function villagesBelow(Region $root)
    {
        $parentIds = [$root->id];
        $villages = collect();
        while ($parentIds !== []) {
            $children = Region::query()->whereIn('parent_id', $parentIds)->orderBy('code')->get();
            $villages = $villages->concat($children->where('level', RegionLevel::Village));
            $parentIds = $children->pluck('id')->all();
        }
        return $villages->sortBy('code')->values();
    }

    /** @param array{villages: \Illuminate\Support\Collection<int, Region>, target: int, existing: \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, PollingStation>>} $plan
     * @return array<int, int>
     */
    private function distribution(array $plan): array
    {
        $distribution = [];
        foreach ($plan['villages'] as $village) {
            $distribution[$village->id] = max(1, $plan['existing']->get($village->id, collect())->count());
        }
        $remaining = $plan['target'] - array_sum($distribution);
        $ids = array_keys($distribution);
        for ($index = 0; $index < $remaining; $index++) {
            $distribution[$ids[$index % count($ids)]]++;
        }
        return $distribution;
    }
}
