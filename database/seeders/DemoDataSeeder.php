<?php

namespace Database\Seeders;

use App\Actions\Elections\CreateElection;
use App\Actions\Elections\ProvisionPollingStations;
use App\Enums\ContestStatus;
use App\Enums\ElectionStatus;
use App\Enums\RegionLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\ContestParticipant;
use App\Models\Election;
use App\Models\ElectionRegion;
use App\Models\EventAssignment;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\TpsAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@electcount.test'],
            [
                'name' => 'Admin Demo',
                'password' => 'password',
                'role' => UserRole::SuperAdmin,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        $jayapura = Region::query()->where('code', '9403')->firstOrFail();
        $city = Region::query()->where('code', '9471')->firstOrFail();

        $districtA = $this->region('94D001', 'Distrik Contoh Jayapura', RegionLevel::District, $jayapura);
        $districtB = $this->region('94D002', 'Distrik Contoh Kota', RegionLevel::District, $city);
        $villages = collect([
            $this->region('94D00101', 'Kampung Demo Harapan', RegionLevel::Village, $districtA),
            $this->region('94D00102', 'Kampung Demo Bersatu', RegionLevel::Village, $districtA),
            $this->region('94D00201', 'Kelurahan Demo Maju', RegionLevel::Village, $districtB),
            $this->region('94D00202', 'Kelurahan Demo Damai', RegionLevel::Village, $districtB),
        ]);

        $papua = Region::query()->where('code', '94')->firstOrFail();
        $election = Election::query()->where('name', 'Simulasi Pilkada Papua 2026 (Data Demo)')->first();

        if (! $election) {
            $election = app(CreateElection::class)->handle($admin, [
                'name' => 'Simulasi Pilkada Papua 2026 (Data Demo)',
                'year' => 2026,
                'type' => 'pilkada',
                'scope_region_id' => $papua->id,
                'starts_at' => now()->addDays(14),
                'contest_name' => 'Kontes Eksekutif Demo',
                'contest_type' => 'executive',
            ]);
        }

        $election->update(['status' => ElectionStatus::Preparing]);
        $election->contests()->updateOrCreate(
            ['name' => 'Kontes Eksekutif Demo'],
            ['type' => 'executive', 'ballot_schema_version' => 'demo-v1', 'status' => ContestStatus::Active],
        );

        $cityElection = Election::query()->where('name', 'Simulasi Pilkada Kota Jayapura 2026 (Data Demo)')->first();

        if (! $cityElection) {
            $cityElection = app(CreateElection::class)->handle($admin, [
                'name' => 'Simulasi Pilkada Kota Jayapura 2026 (Data Demo)',
                'year' => 2026,
                'type' => 'pilkada_kota',
                'scope_region_id' => $city->id,
                'starts_at' => now()->addDays(7),
                'contest_name' => 'Kontes Wali Kota Demo',
                'contest_type' => 'executive',
            ]);
        }

        $cityElection->update(['status' => ElectionStatus::Preparing]);
        $cityElection->contests()->updateOrCreate(
            ['name' => 'Kontes Wali Kota Demo'],
            ['type' => 'executive', 'ballot_schema_version' => 'demo-v1', 'status' => ContestStatus::Active],
        );

        foreach ([$election, $cityElection] as $demoElection) {
            $contest = $demoElection->contests()->firstOrFail();
            foreach (range(1, 3) as $number) {
                ContestParticipant::query()->updateOrCreate(
                    ['election_contest_id' => $contest->id, 'ballot_number' => sprintf('%02d', $number)],
                    ['display_name' => sprintf('Paslon Demo %02d', $number), 'display_order' => $number, 'active' => true],
                );
            }
        }

        Region::query()->where(function ($query): void {
            $query->where('code', '94')->orWhere('code', 'like', '94%');
        })->each(function (Region $region) use ($election): void {
            ElectionRegion::query()->updateOrCreate(
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

        $counts = [5, 5, 4, 4];
        foreach ($villages as $index => $village) {
            $configured = $election->pollingStations()
                ->whereHas('pollingStation', fn ($query) => $query->where('region_id', $village->id))
                ->count();

            if ($configured < $counts[$index]) {
                app(ProvisionPollingStations::class)->handle($admin, $election, $village, $counts[$index]);
            }

            $village->pollingStations()->update(['location_label' => 'Lokasi simulasi — '.$village->name]);
        }

        foreach ($villages->slice(2) as $village) {
            $configured = $cityElection->pollingStations()
                ->whereHas('pollingStation', fn ($query) => $query->where('region_id', $village->id))
                ->count();

            if ($configured < 4) {
                app(ProvisionPollingStations::class)->handle($admin, $cityElection, $village, 4);
            }
        }

        $coordinators = collect([
            $this->user('Koordinator Demo Jayapura', 'coordinator.jayapura@electcount.test', UserRole::Coordinator),
            $this->user('Koordinator Demo Kota', 'coordinator.kota@electcount.test', UserRole::Coordinator),
        ]);

        foreach ([[$coordinators[0], $jayapura], [$coordinators[1], $city]] as [$user, $region]) {
            EventAssignment::query()->updateOrCreate(
                ['user_id' => $user->id, 'election_id' => $election->id],
                ['role' => $user->role, 'assigned_by' => $admin->id],
            );
            RegionAssignment::query()->updateOrCreate(
                ['user_id' => $user->id, 'election_id' => $election->id, 'region_id' => $region->id],
                ['assigned_by' => $admin->id],
            );
        }

        $witnesses = collect(range(1, 6))->map(fn (int $number) => $this->user(
            sprintf('Saksi Demo %02d', $number),
            sprintf('saksi%02d@electcount.test', $number),
            UserRole::Witness,
        ));

        $eventTps = $election->pollingStations()->orderBy('id')->limit($witnesses->count())->get();
        foreach ($witnesses as $index => $witness) {
            EventAssignment::query()->updateOrCreate(
                ['user_id' => $witness->id, 'election_id' => $election->id],
                ['role' => $witness->role, 'assigned_by' => $admin->id],
            );
            TpsAssignment::query()->updateOrCreate(
                ['user_id' => $witness->id, 'election_id' => $election->id, 'election_polling_station_id' => $eventTps[$index]->id],
                ['assigned_by' => $admin->id],
            );
        }

        EventAssignment::query()->updateOrCreate(
            ['user_id' => $coordinators[1]->id, 'election_id' => $cityElection->id],
            ['role' => $coordinators[1]->role, 'assigned_by' => $admin->id],
        );
        RegionAssignment::query()->updateOrCreate(
            ['user_id' => $coordinators[1]->id, 'election_id' => $cityElection->id, 'region_id' => $city->id],
            ['assigned_by' => $admin->id],
        );

        $cityEventTps = $cityElection->pollingStations()->orderBy('id')->firstOrFail();
        EventAssignment::query()->updateOrCreate(
            ['user_id' => $witnesses[0]->id, 'election_id' => $cityElection->id],
            ['role' => $witnesses[0]->role, 'assigned_by' => $admin->id],
        );
        TpsAssignment::query()->updateOrCreate(
            ['user_id' => $witnesses[0]->id, 'election_id' => $cityElection->id, 'election_polling_station_id' => $cityEventTps->id],
            ['assigned_by' => $admin->id],
        );

        AuditLog::query()->updateOrCreate(
            ['action' => 'demo.dataset_seeded', 'object_type' => Election::class, 'object_id' => (string) $election->id],
            ['actor_id' => $admin->id, 'metadata' => ['synthetic' => true, 'tps' => 18, 'label' => 'Data demo lokal']],
        );
        AuditLog::query()->updateOrCreate(
            ['action' => 'demo.dataset_seeded', 'object_type' => Election::class, 'object_id' => (string) $cityElection->id],
            ['actor_id' => $admin->id, 'metadata' => ['synthetic' => true, 'tps' => 8, 'label' => 'Data demo lokal multi-event']],
        );
    }

    private function region(string $code, string $name, RegionLevel $level, Region $parent): Region
    {
        return Region::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'level' => $level,
                'parent_id' => $parent->id,
                'source' => 'demo',
                'source_version' => 'synthetic-local-v1',
            ],
        );
    }

    private function user(string $name, string $email, UserRole $role): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => 'password',
                'role' => $role,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );
    }
}
