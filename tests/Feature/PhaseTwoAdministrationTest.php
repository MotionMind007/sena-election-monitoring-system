<?php

namespace Tests\Feature;

use App\Actions\Elections\CreateElection;
use App\Enums\RegionLevel;
use App\Enums\UserRole;
use App\Models\Election;
use App\Models\EventAssignment;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PapuaRegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PhaseTwoAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PapuaRegionSeeder::class);
    }

    public function test_super_admin_creates_event_with_admin_defined_year_and_region_snapshot(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $papua = Region::query()->where('code', '94')->firstOrFail();

        $response = $this->actingAs($admin)->post('/admin/events', [
            'name' => 'Event Papua Sintetis',
            'year' => 2031,
            'type' => 'pilkada',
            'scope_region_id' => $papua->id,
            'contest_name' => 'Kontes Sintetis',
            'contest_type' => 'executive',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.events.index'));
        $election = Election::query()->firstOrFail();
        $this->assertSame(2031, $election->year);
        $this->assertSame(10, $election->regionSnapshots()->count());
        $this->assertDatabaseHas('election_contests', ['election_id' => $election->id, 'name' => 'Kontes Sintetis']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'election.created', 'object_id' => (string) $election->id]);
    }

    public function test_non_admin_cannot_create_event_or_open_admin_pages(): void
    {
        $coordinator = User::factory()->role(UserRole::Coordinator)->create();
        $papua = Region::query()->where('code', '94')->firstOrFail();

        $this->actingAs($coordinator)->get('/admin/events')->assertForbidden();
        $this->actingAs($coordinator)->post('/admin/events', [
            'name' => 'Ditolak', 'year' => 2031, 'type' => 'pilkada', 'scope_region_id' => $papua->id,
        ])->assertForbidden();
    }

    public function test_super_admin_can_render_phase_two_admin_pages(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();

        $this->actingAs($admin)->get('/admin/events')->assertOk();
        $this->actingAs($admin)->get('/admin/geography')->assertOk();
        $this->actingAs($admin)->get('/admin/assignments')->assertOk();
    }

    public function test_default_geography_contains_only_papua_eight_regencies_and_one_city(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('regions', 10);
        $this->assertSame(1, Region::query()->where('level', 'province')->count());
        $this->assertSame(8, Region::query()->where('level', 'regency')->count());
        $this->assertSame(1, Region::query()->where('level', 'city')->count());
        $this->assertSame(0, Region::query()->whereIn('level', ['district', 'village'])->count());
    }

    public function test_admin_filters_and_paginates_master_regions(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $jayapura = Region::query()->where('code', '9403')->firstOrFail();
        Region::query()->create([
            'code' => '9403230',
            'name' => 'Distrik Sentani',
            'level' => RegionLevel::District,
            'parent_id' => $jayapura->id,
            'source' => 'BPS',
            'source_version' => 'fixture',
        ]);
        Region::query()->create([
            'code' => '94D999',
            'name' => 'Distrik Sentani Demo',
            'level' => RegionLevel::District,
            'parent_id' => $jayapura->id,
            'source' => 'demo',
            'source_version' => 'fixture',
        ]);

        $this->actingAs($admin)->get('/admin/geography?'.http_build_query([
            'q' => 'sentani',
            'level' => 'district',
            'regency_id' => $jayapura->id,
            'source' => 'BPS',
        ]))->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Geography/Index')
            ->where('filters.q', 'sentani')
            ->where('regions.total', 1)
            ->where('regions.data.0.code', '9403230')
            ->has('filterOptions.regencies', 9));
    }

    public function test_unavailable_source_is_ignored_for_the_selected_region_level(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $jayapura = Region::query()->where('code', '9403')->firstOrFail();

        $this->actingAs($admin)->get('/admin/geography?'.http_build_query([
            'level' => 'regency',
            'regency_id' => $jayapura->id,
            'source' => 'demo',
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('filters.source', '')
            ->where('regions.total', 1)
            ->where('regions.data.0.code', '9403')
            ->where('regions.data.0.name', 'Kabupaten Jayapura'));
    }

    public function test_super_admin_creates_manual_region_hierarchy_with_audit_and_preparing_snapshot(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $papua = Region::query()->where('code', '94')->firstOrFail();
        $jayapura = Region::query()->where('code', '9403')->firstOrFail();
        $election = app(CreateElection::class)->handle($admin, [
            'name' => 'Event Manual Region',
            'year' => 2031,
            'type' => 'pilkada',
            'scope_region_id' => $papua->id,
            'starts_at' => null,
        ]);

        $this->actingAs($admin)->post(route('admin.geography.store'), [
            'level' => 'district',
            'parent_id' => $jayapura->id,
            'name' => 'Distrik Manual',
        ])->assertSessionHasNoErrors();

        $district = Region::query()->where('name', 'Distrik Manual')->firstOrFail();
        $this->assertStringStartsWith('MAN-', $district->code);
        $this->assertSame('manual', $district->source);
        $this->assertSame($jayapura->id, $district->parent_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'region.created_manually',
            'object_id' => (string) $district->id,
        ]);
        $this->assertDatabaseHas('election_regions', [
            'election_id' => $election->id,
            'region_id' => $district->id,
            'source_snapshot' => 'manual',
        ]);

        $this->actingAs($admin)->post(route('admin.geography.store'), [
            'level' => 'village',
            'parent_id' => $district->id,
            'name' => 'Kampung Manual',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('regions', [
            'name' => 'Kampung Manual',
            'level' => 'village',
            'parent_id' => $district->id,
            'source' => 'manual',
        ]);
    }

    public function test_manual_region_rejects_invalid_parent_duplicate_name_and_non_admin(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $coordinator = User::factory()->role(UserRole::Coordinator)->create();
        $papua = Region::query()->where('code', '94')->firstOrFail();
        $jayapura = Region::query()->where('code', '9403')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.geography.store'), [
            'level' => 'district',
            'parent_id' => $papua->id,
            'name' => 'Distrik Salah Induk',
        ])->assertSessionHasErrors('parent_id');

        $this->actingAs($admin)->post(route('admin.geography.store'), [
            'name' => 'Kabupaten Jayapura',
            'level' => 'regency',
            'parent_id' => $papua->id,
        ])->assertSessionHasErrors('level');

        $this->actingAs($coordinator)->post(route('admin.geography.store'), [
            'level' => 'district',
            'parent_id' => $jayapura->id,
            'name' => 'Distrik Terlarang',
        ])->assertForbidden();
    }

    public function test_super_admin_manages_event_status_contest_and_participant_photo(): void
    {
        Storage::fake('local');
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $papua = Region::query()->where('code', '94')->firstOrFail();
        $election = app(CreateElection::class)->handle($admin, [
            'name' => 'Event Terkelola', 'year' => 2031, 'type' => 'pilkada', 'scope_region_id' => $papua->id,
            'starts_at' => null, 'contest_name' => 'Gubernur Papua', 'contest_type' => 'executive',
        ]);
        $contest = $election->contests()->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.events.status', $election), ['status' => 'active'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch(route('admin.contests.update', $contest), ['status' => 'active', 'ballot_schema_version' => 'candidate-v1'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.participants.store', $contest), [
            'ballot_number' => '01', 'display_name' => 'Paslon Sintetis', 'display_order' => 1, 'active' => true,
            'photo' => UploadedFile::fake()->image('paslon.jpg', 600, 800),
        ])->assertSessionHasNoErrors();

        $participant = $contest->participants()->firstOrFail();
        $this->assertSame('active', $election->fresh()->status->value);
        $this->assertSame('candidate-v1', $contest->fresh()->ballot_schema_version);
        Storage::disk('local')->assertExists($participant->photo_path);
        $this->actingAs($admin)->get(route('participants.photo', $participant))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'contest_participant.created', 'object_id' => (string) $participant->id]);
    }

    public function test_region_import_preview_commit_and_duplicate_validation(): void
    {
        Storage::fake('local');
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $csv = "provinsi,kabupaten_kota,distrik,kelurahan_kampung\nPapua,Kabupaten Jayapura,Distrik Sintetis,Kampung Sintetis\n";

        $this->actingAs($admin)->post('/admin/geography/imports/preview', [
            'source' => 'BPS',
            'source_version' => 'Fixture BPS Sintetis',
            'file' => UploadedFile::fake()->createWithContent('papua.csv', $csv),
        ])->assertSessionHasNoErrors();

        $importId = $this->app['db']->table('geography_imports')->value('id');
        $this->actingAs($admin)->post("/admin/geography/imports/{$importId}/commit")->assertSessionHasNoErrors();
        $this->assertDatabaseHas('regions', ['name' => 'Kampung Sintetis', 'level' => 'village', 'source' => 'BPS']);

        $duplicate = "provinsi,kabupaten_kota,distrik,kelurahan_kampung\nPapua,,Distrik Tanpa Kabupaten,Kampung Salah\n";
        $this->actingAs($admin)->post('/admin/geography/imports/preview', [
            'source' => 'BPS',
            'source_version' => 'Fixture',
            'file' => UploadedFile::fake()->createWithContent('duplicate.csv', $duplicate),
        ])->assertSessionHasErrors('file');
    }

    public function test_region_import_accepts_numeric_papua_root_in_complete_hierarchy(): void
    {
        Storage::fake('local');
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $csv = "provinsi,kabupaten_kota,distrik,kelurahan_kampung\nPapua,Kabupaten Jayapura,Distrik Sentani,\n";

        $this->actingAs($admin)->post('/admin/geography/imports/preview', [
            'source' => 'BPS',
            'source_version' => 'BPS SIG 2025 Semester 2',
            'file' => UploadedFile::fake()->createWithContent('papua-complete.csv', $csv),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('geography_imports', [
            'source' => 'BPS',
            'source_version' => 'BPS SIG 2025 Semester 2',
            'status' => 'previewed',
        ]);
    }

    public function test_region_import_rejects_invalid_header_and_broken_hierarchy(): void
    {
        Storage::fake('local');
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $cycle = "code,name,level,parent_code\nA,Distrik A,district,B\n";

        $this->actingAs($admin)->post('/admin/geography/imports/preview', [
            'source' => 'BPS', 'source_version' => 'Fixture', 'file' => UploadedFile::fake()->createWithContent('cycle.csv', $cycle),
        ])->assertSessionHasErrors('file');

        $outside = "provinsi,kabupaten_kota,distrik,kelurahan_kampung\nPapua,,Distrik Terputus,\n";
        $this->actingAs($admin)->post('/admin/geography/imports/preview', [
            'source' => 'BPS', 'source_version' => 'Fixture', 'file' => UploadedFile::fake()->createWithContent('outside.csv', $outside),
        ])->assertSessionHasErrors('file');
    }

    public function test_super_admin_imports_official_bps_rows_with_stable_codes(): void
    {
        Storage::fake('local');
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $csv = "code,name,level,parent_code\n94,Papua,province,\n9403,Kabupaten Jayapura,regency,94\n940399,Distrik Resmi,district,9403\n9403992001,Kampung Resmi,village,940399\n";

        $this->actingAs($admin)->post('/admin/geography/imports/preview', [
            'source' => 'BPS',
            'source_version' => 'BPS SIG Fixture',
            'file' => UploadedFile::fake()->createWithContent('bps-papua.csv', $csv),
        ])->assertSessionHasNoErrors();

        $importId = $this->app['db']->table('geography_imports')->value('id');
        $this->actingAs($admin)->post("/admin/geography/imports/{$importId}/commit")->assertSessionHasNoErrors();

        $this->assertDatabaseHas('regions', ['code' => '9403992001', 'name' => 'Kampung Resmi', 'level' => 'village', 'source' => 'BPS']);
    }

    public function test_official_bps_import_reconciles_matching_manual_region_without_losing_its_id(): void
    {
        Storage::fake('local');
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $jayapura = Region::query()->where('code', '9403')->firstOrFail();
        $manual = Region::query()->create(['code' => 'MAN-OLD', 'name' => 'Distrik Sama', 'level' => RegionLevel::District, 'parent_id' => $jayapura->id, 'source' => 'manual']);
        $csv = "code,name,level,parent_code\n94,Papua,province,\n9403,Kabupaten Jayapura,regency,94\n940399,Distrik Sama,district,9403\n";

        $this->actingAs($admin)->post('/admin/geography/imports/preview', [
            'source' => 'BPS', 'source_version' => 'BPS SIG Fixture',
            'file' => UploadedFile::fake()->createWithContent('bps-reconcile.csv', $csv),
        ])->assertSessionHasNoErrors();
        $importId = $this->app['db']->table('geography_imports')->value('id');
        $this->actingAs($admin)->post("/admin/geography/imports/{$importId}/commit")->assertSessionHasNoErrors();

        $this->assertDatabaseHas('regions', ['id' => $manual->id, 'code' => '940399', 'source' => 'BPS']);
    }

    public function test_super_admin_can_rename_or_delete_unused_region_but_not_used_region(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $jayapura = Region::query()->where('code', '9403')->firstOrFail();
        $region = Region::query()->create(['code' => 'MAN-EDIT', 'name' => 'Distrik Lama', 'level' => RegionLevel::District, 'parent_id' => $jayapura->id, 'source' => 'manual']);

        $this->actingAs($admin)->patch(route('admin.geography.update', $region), ['name' => 'Distrik Baru'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('regions', ['id' => $region->id, 'name' => 'Distrik Baru']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'region.updated', 'object_id' => (string) $region->id]);

        $this->actingAs($admin)->delete(route('admin.geography.destroy', $region))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('regions', ['id' => $region->id]);

        $used = Region::query()->create(['code' => 'MAN-USED', 'name' => 'Distrik Dipakai', 'level' => RegionLevel::District, 'parent_id' => $jayapura->id, 'source' => 'manual']);
        Region::query()->create(['code' => 'MAN-USED-V', 'name' => 'Kampung Dipakai', 'level' => RegionLevel::Village, 'parent_id' => $used->id, 'source' => 'manual']);
        $this->actingAs($admin)->delete(route('admin.geography.destroy', $used))->assertSessionHasErrors('region');
        $this->assertDatabaseHas('regions', ['id' => $used->id]);
    }

    public function test_admin_provisions_tps_idempotently_after_village_is_in_event_snapshot(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $papua = Region::query()->where('code', '94')->firstOrFail();
        $jayapura = Region::query()->where('code', '9403')->firstOrFail();
        $district = Region::query()->create(['code' => '940399', 'name' => 'Distrik Sintetis', 'level' => RegionLevel::District, 'parent_id' => $jayapura->id, 'source' => 'BPS', 'source_version' => 'fixture']);
        $village = Region::query()->create(['code' => '9403992001', 'name' => 'Kampung Sintetis', 'level' => RegionLevel::Village, 'parent_id' => $district->id, 'source' => 'BPS', 'source_version' => 'fixture']);
        $election = app(CreateElection::class)->handle($admin, ['name' => 'Event Sintetis', 'year' => 2031, 'type' => 'pilkada', 'scope_region_id' => $papua->id, 'starts_at' => null]);

        $payload = ['election_id' => $election->id, 'region_id' => $village->id, 'count' => 3];
        $this->actingAs($admin)->post('/admin/polling-stations/provision', $payload)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/polling-stations/provision', $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('polling_stations', 3);
        $this->assertDatabaseCount('election_polling_stations', 3);
    }

    public function test_admin_creates_named_master_tps_from_valid_region_hierarchy(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $province = Region::query()->where('code', '94')->firstOrFail();
        $regency = Region::query()->where('code', '9403')->firstOrFail();
        $district = Region::query()->create(['code' => 'TPS-DISTRICT', 'name' => 'Distrik TPS', 'level' => RegionLevel::District, 'parent_id' => $regency->id, 'source' => 'manual']);
        $village = Region::query()->create(['code' => 'TPS-VILLAGE', 'name' => 'Kampung TPS', 'level' => RegionLevel::Village, 'parent_id' => $district->id, 'source' => 'manual']);
        $election = app(CreateElection::class)->handle($admin, ['name' => 'Event TPS', 'year' => 2031, 'type' => 'pilkada', 'scope_region_id' => $province->id, 'starts_at' => null]);

        $payload = [
            'name' => 'TPS 001',
            'province_id' => $province->id,
            'regency_id' => $regency->id,
            'district_id' => $district->id,
            'region_id' => $village->id,
        ];

        $this->actingAs($admin)->post(route('admin.polling-stations.store'), $payload)->assertSessionHasNoErrors();
        $stationId = $this->app['db']->table('polling_stations')->where('location_label', 'TPS 001')->value('id');
        $this->assertNotNull($stationId);
        $this->assertDatabaseHas('election_polling_stations', ['election_id' => $election->id, 'polling_station_id' => $stationId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'polling_station.created_manually', 'object_id' => (string) $stationId]);

        $this->actingAs($admin)->post(route('admin.polling-stations.store'), $payload)->assertSessionHasErrors('name');

        $this->actingAs($admin)->post(route('admin.polling-stations.store'), [
            ...$payload,
            'name' => 'TPS 002',
            'district_id' => $regency->id,
        ])->assertSessionHasErrors('region_id');
    }

    public function test_coordinator_cannot_read_sibling_region_through_api(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $coordinator = User::factory()->role(UserRole::Coordinator)->create();
        $papua = Region::query()->where('code', '94')->firstOrFail();
        $jayapura = Region::query()->where('code', '9403')->firstOrFail();
        $keerom = Region::query()->where('code', '9420')->firstOrFail();
        $district = Region::query()->create(['code' => '940398', 'name' => 'Distrik Sintetis', 'level' => RegionLevel::District, 'parent_id' => $jayapura->id, 'source' => 'BPS', 'source_version' => 'fixture']);
        $election = app(CreateElection::class)->handle($admin, ['name' => 'Event Sintetis', 'year' => 2031, 'type' => 'pilkada', 'scope_region_id' => $papua->id, 'starts_at' => null]);
        EventAssignment::query()->create(['user_id' => $coordinator->id, 'election_id' => $election->id, 'role' => $coordinator->role, 'assigned_by' => $admin->id]);
        RegionAssignment::query()->create(['user_id' => $coordinator->id, 'election_id' => $election->id, 'region_id' => $jayapura->id, 'assigned_by' => $admin->id]);

        $this->actingAs($coordinator)->getJson("/api/v1/events/{$election->id}/regions/{$jayapura->id}/children")
            ->assertOk()->assertJsonFragment(['code' => $district->code]);
        $this->actingAs($coordinator)->getJson("/api/v1/events/{$election->id}/regions/{$keerom->id}/children")
            ->assertNotFound();
    }

    public function test_admin_creates_internal_user_and_region_assignment(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $papua = Region::query()->where('code', '94')->firstOrFail();
        $jayapura = Region::query()->where('code', '9403')->firstOrFail();
        $election = app(CreateElection::class)->handle($admin, ['name' => 'Event Sintetis', 'year' => 2031, 'type' => 'pilkada', 'scope_region_id' => $papua->id, 'starts_at' => null]);

        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Koordinator Sintetis',
            'email' => 'coordinator@example.test',
            'role' => 'coordinator',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasNoErrors();

        $coordinator = User::query()->where('email', 'coordinator@example.test')->firstOrFail();
        $this->actingAs($admin)->post('/admin/assignments', [
            'user_id' => $coordinator->id,
            'election_id' => $election->id,
            'scope_type' => 'region',
            'region_id' => $jayapura->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('event_assignments', ['user_id' => $coordinator->id, 'election_id' => $election->id]);
        $this->assertDatabaseHas('region_assignments', ['user_id' => $coordinator->id, 'region_id' => $jayapura->id]);
    }
}
