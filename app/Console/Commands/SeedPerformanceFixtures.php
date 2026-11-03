<?php

namespace App\Console\Commands;

use App\Enums\ContestStatus;
use App\Enums\ElectionStatus;
use App\Enums\RegionLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\ContestParticipant;
use App\Models\Election;
use App\Models\ElectionContest;
use App\Models\Region;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SeedPerformanceFixtures extends Command
{
    protected $signature = 'ops:seed-load-fixtures {--confirm : Create disposable P4 synthetic data} {--password= : Staging-only password for synthetic accounts}';

    protected $description = 'Seed the approved P4 synthetic capacity profile on disposable staging only';

    public function handle(): int
    {
        if (! app()->environment('staging')) {
            $this->error('Fixture P4 hanya boleh dijalankan pada APP_ENV=staging.');

            return self::FAILURE;
        }
        if (! $this->option('confirm')) {
            $this->error('Tambahkan --confirm untuk membuat data sintetis P4.');

            return self::FAILURE;
        }

        $password = (string) $this->option('password');
        if (mb_strlen($password) < 16) {
            $this->error('Gunakan password staging khusus minimal 16 karakter melalui --password.');

            return self::FAILURE;
        }
        if (Election::query()->where('name', 'P4 Sintetis Provinsi 5000 TPS')->exists()) {
            $this->warn('Fixture P4 sudah tersedia; tidak dibuat ulang.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($password): void {
            $now = now();
            $admin = User::query()->create([
                'name' => 'P4 Fixture Owner',
                'email' => 'p4-owner@staging.invalid',
                'password' => Hash::make($password),
                'role' => UserRole::SuperAdmin,
                'status' => UserStatus::Active,
                'email_verified_at' => $now,
            ]);

            [$province, $regencyOne, $regencyTwo, $regencyThree] = $this->regions();
            $provinceElection = $this->election($admin, $province, 'P4 Sintetis Provinsi 5000 TPS');
            $regencyElectionOne = $this->election($admin, $regencyTwo, 'P4 Sintetis Kabupaten A 600 TPS');
            $regencyElectionTwo = $this->election($admin, $regencyThree, 'P4 Sintetis Kabupaten B 600 TPS');

            $provinceStations = $this->stations($admin, $provinceElection, $province, $regencyOne, 5000, 'P4-P');
            $this->stations($admin, $regencyElectionOne, $regencyTwo, $regencyTwo, 600, 'P4-A');
            $this->stations($admin, $regencyElectionTwo, $regencyThree, $regencyThree, 600, 'P4-B');
            $this->accounts($admin, $provinceElection, $province, $provinceStations, $password);

            AuditLog::query()->create([
                'actor_id' => $admin->id,
                'action' => 'performance_fixture.seeded',
                'object_type' => Election::class,
                'object_id' => (string) $provinceElection->id,
                'metadata' => ['synthetic' => true, 'province_tps' => 5000, 'regency_tps_each' => 600, 'accounts' => 1000],
            ]);
        });

        $events = Election::query()->whereLike('name', 'P4 Sintetis%')->with('contests')->get();
        $this->table(['Event', 'Event ID', 'Contest ID', 'TPS'], $events->map(fn (Election $event) => [
            $event->name,
            $event->id,
            $event->contests->first()?->id,
            $event->pollingStations()->count(),
        ])->all());
        $this->info('Fixture P4 siap. Akun sintetis memakai email p4-witness-0001@staging.invalid hingga p4-admin-0020@staging.invalid.');

        return self::SUCCESS;
    }

    /** @return array{Region, Region, Region, Region} */
    private function regions(): array
    {
        $province = Region::query()->create(['code' => 'P4-94', 'name' => 'Provinsi P4 Sintetis', 'level' => RegionLevel::Province, 'source' => 'performance-fixture', 'source_version' => 'P4-v1']);
        $regencyOne = $this->region('P4-9401', 'Kabupaten P4 Utama', RegionLevel::Regency, $province);
        $regencyTwo = $this->region('P4-9402', 'Kabupaten P4 A', RegionLevel::Regency, $province);
        $regencyThree = $this->region('P4-9403', 'Kabupaten P4 B', RegionLevel::Regency, $province);

        return [$province, $regencyOne, $regencyTwo, $regencyThree];
    }

    private function election(User $admin, Region $scope, string $name): Election
    {
        $election = Election::query()->create(['name' => $name, 'year' => 2030, 'type' => 'pilkada', 'scope_region_id' => $scope->id, 'status' => ElectionStatus::Active, 'created_by' => $admin->id]);
        $contest = ElectionContest::query()->create(['election_id' => $election->id, 'name' => 'Kontes P4 Sintetis', 'type' => 'executive', 'ballot_schema_version' => 'candidate-pair-v1', 'status' => ContestStatus::Active]);
        foreach (range(1, 3) as $number) {
            ContestParticipant::query()->create(['election_contest_id' => $contest->id, 'participant_type' => 'candidate', 'ballot_number' => sprintf('%02d', $number), 'display_name' => "Paslon P4 Sintetis {$number}", 'display_order' => $number, 'active' => true]);
        }

        return $election;
    }

    /** @return array<int, int> */
    private function stations(User $admin, Election $election, Region $scope, Region $stationRegency, int $count, string $prefix): array
    {
        $district = $this->region("{$prefix}-D", "Distrik {$prefix}", RegionLevel::District, $stationRegency);
        $village = $this->region("{$prefix}-V", "Kampung {$prefix}", RegionLevel::Village, $district);
        $snapshotRegions = collect([$scope, $stationRegency, $district, $village])->unique('id')->map(fn (Region $region) => ['election_id' => $election->id, 'region_id' => $region->id, 'code_snapshot' => $region->code, 'name_snapshot' => $region->name, 'level_snapshot' => $region->level->value, 'source_snapshot' => $region->source, 'source_version_snapshot' => $region->source_version, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('election_regions')->insert($snapshotRegions->all());
        $rows = collect(range(1, $count))->map(fn (int $number) => ['region_id' => $village->id, 'code' => sprintf('%s-TPS-%05d', $prefix, $number), 'number' => $number, 'location_label' => sprintf('TPS %03d P4 Sintetis', $number), 'source' => 'performance-fixture', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($rows->chunk(500) as $chunk) {
            DB::table('polling_stations')->insert($chunk->all());
        }
        $stationIds = DB::table('polling_stations')->where('region_id', $village->id)->orderBy('id')->pluck('id')->all();
        foreach (collect($stationIds)->chunk(500) as $chunk) {
            DB::table('election_polling_stations')->insert($chunk->map(fn (int $stationId) => ['election_id' => $election->id, 'polling_station_id' => $stationId, 'eligible' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()])->all());
        }

        return DB::table('election_polling_stations')->where('election_id', $election->id)->orderBy('id')->pluck('id')->all();
    }

    /** @param array<int, int> $stationIds */
    private function accounts(User $owner, Election $election, Region $scope, array $stationIds, string $password): void
    {
        $hash = Hash::make($password);
        $now = now();
        $make = function (string $prefix, int $count, UserRole $role) use ($hash, $now): array {
            return collect(range(1, $count))->map(fn (int $number) => ['name' => sprintf('P4 %s %04d', $role->label(), $number), 'email' => sprintf('p4-%s-%04d@staging.invalid', $prefix, $number), 'password' => $hash, 'role' => $role->value, 'status' => UserStatus::Active->value, 'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now])->all();
        };
        DB::table('users')->insert([...$make('witness', 700, UserRole::Witness), ...$make('coordinator', 280, UserRole::Coordinator), ...$make('admin', 20, UserRole::SuperAdmin)]);

        $witnesses = DB::table('users')->whereLike('email', 'p4-witness-%@staging.invalid')->orderBy('id')->pluck('id')->all();
        $coordinators = DB::table('users')->whereLike('email', 'p4-coordinator-%@staging.invalid')->orderBy('id')->pluck('id')->all();
        $assignments = [];
        foreach ($witnesses as $index => $userId) {
            $assignments[] = ['user_id' => $userId, 'election_id' => $election->id, 'role' => UserRole::Witness->value, 'assigned_by' => $owner->id, 'created_at' => $now, 'updated_at' => $now];
            DB::table('tps_assignments')->insert(['user_id' => $userId, 'election_id' => $election->id, 'election_polling_station_id' => $stationIds[$index], 'assigned_by' => $owner->id, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ($coordinators as $userId) {
            $assignments[] = ['user_id' => $userId, 'election_id' => $election->id, 'role' => UserRole::Coordinator->value, 'assigned_by' => $owner->id, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($assignments, 500) as $chunk) {
            DB::table('event_assignments')->insert($chunk);
        }
        foreach (array_chunk($coordinators, 500) as $chunk) {
            DB::table('region_assignments')->insert(array_map(fn (int $userId) => ['user_id' => $userId, 'election_id' => $election->id, 'region_id' => $scope->id, 'assigned_by' => $owner->id, 'created_at' => $now, 'updated_at' => $now], $chunk));
        }
    }

    private function region(string $code, string $name, RegionLevel $level, Region $parent): Region
    {
        return Region::query()->create(['code' => $code, 'name' => $name, 'level' => $level, 'parent_id' => $parent->id, 'source' => 'performance-fixture', 'source_version' => 'P4-v1']);
    }
}
