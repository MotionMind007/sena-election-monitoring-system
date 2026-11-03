<?php

namespace Tests\Feature;

use App\Models\Election;
use App\Models\User;
use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseSevenOperationalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoScenarioSeeder::class);
    }

    public function test_only_super_admin_can_access_operations_audit_and_export(): void
    {
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $event = Election::query()->with('contests')->firstOrFail();
        $query = ['event' => $event->id, 'contest' => $event->contests->first()->id];

        $this->actingAs($witness)->get(route('admin.operations.index'))->assertForbidden();
        $this->actingAs($witness)->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($witness)->get(route('admin.exports.results', $query))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.operations.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.audit.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.exports.results', $query))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertDatabaseHas('audit_logs', ['action' => 'results.exported', 'actor_id' => $admin->id]);
    }

    public function test_export_rejects_contest_from_another_event(): void
    {
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $events = Election::query()->with('contests')->get();

        $this->actingAs($admin)->get(route('admin.exports.results', ['event' => $events[0]->id, 'contest' => $events[1]->contests->first()->id]))->assertNotFound();
    }
}
