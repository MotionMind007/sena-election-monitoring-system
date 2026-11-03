<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Models\ElectionPollingStation;
use App\Models\User;
use App\Models\VoteSubmission;
use App\Services\ScopeAccess;
use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductionPhaseFourPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoScenarioSeeder::class);
    }

    public function test_approval_queue_is_database_paginated_with_bounded_queries(): void
    {
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $station = ElectionPollingStation::query()->with('election.contests')->firstOrFail();
        $contest = $station->election->contests->firstOrFail();
        foreach (range(1, 35) as $version) {
            $this->submission($witness, $station, $contest->id, $version);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($admin)->get(route('verification.index'));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('submissions.data', 30)
            ->where('submissions.total', 35)
            ->where('submissions.last_page', 2)
            ->where('submissions.current_page', 1));
        $this->assertLessThanOrEqual(10, $queryCount, "Approval queue executed {$queryCount} queries.");
    }

    public function test_coordinator_pagination_scope_is_applied_in_sql_before_rows_are_loaded(): void
    {
        $coordinator = User::query()->where('email', 'coordinator.jayapura@electcount.test')->firstOrFail();
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $scope = app(ScopeAccess::class);
        $stations = ElectionPollingStation::query()->with(['election.contests', 'pollingStation.region.parent'])->get();
        $allowed = $stations->first(fn (ElectionPollingStation $station) => $scope->canAccessPollingStation($coordinator, $station));
        $blocked = $stations->first(fn (ElectionPollingStation $station) => ! $scope->canAccessPollingStation($coordinator, $station));
        $this->assertNotNull($allowed);
        $this->assertNotNull($blocked);

        $visible = $this->submission($witness, $allowed, $allowed->election->contests->firstOrFail()->id, 1);
        $hidden = $this->submission($witness, $blocked, $blocked->election->contests->firstOrFail()->id, 1);

        $this->actingAs($coordinator)->get(route('verification.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('submissions.data', 1)
                ->where('submissions.data.0.id', $visible->id)
                ->where('submissions.total', 1));
        $this->assertNotSame($visible->id, $hidden->id);
    }

    public function test_short_lived_user_scoped_dashboard_cache_reduces_repeat_queries(): void
    {
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        config()->set('performance.dashboard_cache_ttl_seconds', 60);
        Cache::flush();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $firstQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $secondQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan($firstQueryCount, $secondQueryCount);
        $this->assertGreaterThanOrEqual(2, $firstQueryCount - $secondQueryCount);
    }

    private function submission(User $submitter, ElectionPollingStation $station, int $contestId, int $version): VoteSubmission
    {
        $submission = VoteSubmission::query()->create([
            'election_id' => $station->election_id,
            'election_contest_id' => $contestId,
            'election_polling_station_id' => $station->id,
            'submitter_id' => $submitter->id,
            'status' => SubmissionStatus::Submitted,
            'entry_mode' => 'direct',
            'idempotency_key' => (string) Str::uuid(),
            'version' => $version,
            'source_name' => $submitter->name,
            'source_method' => 'direct',
            'submitted_at' => now(),
        ]);
        $submission->totals()->create(['valid_votes' => 270, 'invalid_votes' => 3, 'total_votes' => 273]);

        return $submission;
    }
}
