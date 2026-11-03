<?php

namespace Database\Seeders;

use App\Enums\RegionLevel;
use App\Models\Region;
use Illuminate\Database\Seeder;

class PapuaRegionSeeder extends Seeder
{
    public function run(): void
    {
        $sourceVersion = 'BPS Papua 2025 / KepBPS 646-2024';

        $province = Region::query()->updateOrCreate(
            ['code' => '94'],
            [
                'name' => 'Papua',
                'level' => RegionLevel::Province,
                'parent_id' => null,
                'source' => 'BPS',
                'source_version' => $sourceVersion,
            ],
        );

        $regions = [
            ['9403', 'Kabupaten Jayapura', RegionLevel::Regency],
            ['9408', 'Kabupaten Kepulauan Yapen', RegionLevel::Regency],
            ['9409', 'Kabupaten Biak Numfor', RegionLevel::Regency],
            ['9419', 'Kabupaten Sarmi', RegionLevel::Regency],
            ['9420', 'Kabupaten Keerom', RegionLevel::Regency],
            ['9426', 'Kabupaten Waropen', RegionLevel::Regency],
            ['9427', 'Kabupaten Supiori', RegionLevel::Regency],
            ['9428', 'Kabupaten Mamberamo Raya', RegionLevel::Regency],
            ['9471', 'Kota Jayapura', RegionLevel::City],
        ];

        foreach ($regions as [$code, $name, $level]) {
            Region::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'level' => $level,
                    'parent_id' => $province->id,
                    'source' => 'BPS',
                    'source_version' => $sourceVersion,
                ],
            );
        }
    }
}
