<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PapuaRegionSeeder::class);
        $this->call(DemoDataSeeder::class);
    }
}
