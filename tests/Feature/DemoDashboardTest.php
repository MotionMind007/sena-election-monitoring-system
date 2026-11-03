<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Election;
use App\Models\ElectionPollingStation;
use App\Models\User;
use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DemoDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_is_idempotent_for_operational_records(): void
    {
        $this->seed(DemoScenarioSeeder::class);
        $auditCount = AuditLog::query()->count();
        $this->seed(DemoScenarioSeeder::class);

        $this->assertDatabaseCount('elections', 2);
        $this->assertDatabaseCount('polling_stations', 18);
        $this->assertDatabaseCount('election_polling_stations', 26);
        $this->assertDatabaseCount('event_assignments', 10);
        $this->assertDatabaseCount('tps_assignments', 7);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_admin_dashboard_displays_seeded_demo_data(): void
    {
        $this->seed(DemoScenarioSeeder::class);
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('event.name', 'Simulasi Pilkada Papua 2026 (Data Demo)')
                ->has('eventOptions', 2)
                ->where('stats.total', 18)
                ->where('stats.assigned', 6)
                ->where('demoPreview.stats.total', 18)
                ->where('demoPreview.stats.submitted', 0)
                ->where('demoPreview.stats.verified', 0)
                ->has('demoPreview.regions', 9)
                ->has('demoPreview.candidates', 3)
                ->has('demoPreview.monitoring', 18)
                ->where('demoPreview.candidates.0.photo_url', null));
    }

    public function test_admin_can_switch_between_events_in_the_same_year(): void
    {
        $this->seed(DemoScenarioSeeder::class);
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $cityElection = Election::query()->where('name', 'Simulasi Pilkada Kota Jayapura 2026 (Data Demo)')->firstOrFail();

        $this->actingAs($admin)->get('/dashboard?event='.$cityElection->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('event.id', $cityElection->id)
                ->where('event.year', 2026)
                ->where('event.contest', 'Kontes Wali Kota Demo')
                ->where('stats.total', 8)
                ->where('demoPreview.stats.total', 8)
                ->where('demoPreview.stats.verified', 0)
                ->has('demoPreview.regions', 1));
    }

    public function test_dashboard_respects_coordinator_and_witness_scope(): void
    {
        $this->seed(DemoScenarioSeeder::class);
        $coordinator = User::query()->where('email', 'coordinator.jayapura@electcount.test')->firstOrFail();
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();

        $this->actingAs($coordinator)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 10)
                ->has('regionFilters.regencies', 1)
                ->has('eventOptions', 1));
        $this->actingAs($witness)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('stats.total', 1));

        $this->assertSame(18, ElectionPollingStation::query()->where('election_id', Election::query()->value('id'))->count());

        $cityElection = Election::query()->where('name', 'Simulasi Pilkada Kota Jayapura 2026 (Data Demo)')->firstOrFail();
        $this->actingAs($coordinator)->get('/dashboard?event='.$cityElection->id)->assertNotFound();
    }
}
