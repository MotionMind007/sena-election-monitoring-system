<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Models\Election;
use App\Models\ElectionPollingStation;
use App\Models\Region;
use App\Models\User;
use App\Models\VoteSubmission;
use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PhaseFiveDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DemoScenarioSeeder::class);
    }

    public function test_local_papua_map_contains_six_provinces_and_supiori(): void
    {
        $provinces = json_decode(file_get_contents(resource_path('js/Data/papua-provinces.json')), true, flags: JSON_THROW_ON_ERROR);
        $regencies = json_decode(file_get_contents(resource_path('js/Data/papua-regencies.json')), true, flags: JSON_THROW_ON_ERROR);
        $districts = json_decode(file_get_contents(resource_path('js/Data/papua-districts.json')), true, flags: JSON_THROW_ON_ERROR);
        $villages = json_decode(file_get_contents(resource_path('js/Data/papua-villages.json')), true, flags: JSON_THROW_ON_ERROR);
        $provinceNames = collect($provinces['features'])->pluck('properties.wadmpr')->sort()->values()->all();
        $regencyNames = collect($regencies['features'])->pluck('properties.namobj')->all();

        $this->assertSame(['Papua', 'Papua Barat', 'Papua Barat Daya', 'Papua Pegunungan', 'Papua Selatan', 'Papua Tengah'], $provinceNames);
        $this->assertContains('Supiori', $regencyNames);
        $this->assertCount(108, $districts['features']);
        $this->assertCount(1040, $villages['features']);
        $this->assertCount(63, collect($villages['features'])->where('properties.WADMKK', 'Supiori'));
        $mapComponent = file_get_contents(resource_path('js/Components/PapuaRegionMap.vue'));
        $this->assertStringContainsString('https://tile.openstreetmap.org/{z}/{x}/{y}.png', $mapComponent);
        $this->assertStringContainsString('OpenStreetMap</a> contributors', $mapComponent);
    }

    public function test_only_active_verified_result_contributes_to_real_count(): void
    {
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $station = $witness->tpsAssignments()->with('electionPollingStation.election')->firstOrFail()->electionPollingStation;
        $contest = $station->election->contests()->with('participants')->firstOrFail();

        $draft = VoteSubmission::query()->create([
            'election_id' => $station->election_id,
            'election_contest_id' => $contest->id,
            'election_polling_station_id' => $station->id,
            'submitter_id' => $witness->id,
            'status' => SubmissionStatus::Draft,
            'entry_mode' => 'direct',
            'idempotency_key' => (string) Str::uuid(),
            'version' => 1,
        ]);
        foreach ($contest->participants as $participant) {
            $draft->items()->create(['contest_participant_id' => $participant->id, 'votes' => 9999, 'ballot_dimension' => 'candidate']);
        }

        $payload = [
            'election_id' => $station->election_id,
            'election_contest_id' => $contest->id,
            'election_polling_station_id' => $station->id,
            'intent' => 'submit',
            'idempotency_key' => (string) Str::uuid(),
            'items' => $contest->participants->values()->map(fn ($participant, $index) => ['participant_id' => $participant->id, 'votes' => 120 - ($index * 20)])->all(),
            'invalid_votes' => 7,
            'evidence' => [UploadedFile::fake()->image('c-hasil.jpg', 1280, 960)],
        ];
        $this->actingAs($witness)->post(route('reports.store'), $payload)->assertRedirect();
        $submission = VoteSubmission::query()->where('status', SubmissionStatus::Submitted)->firstOrFail();

        $this->actingAs($admin)->get('/dashboard?event='.$station->election_id.'&contest='.$contest->id)
            ->assertInertia(fn (Assert $page) => $page->where('stats.submitted', 1)->where('stats.verified', 0)->where('demoPreview.votes.valid', 0));

        $this->actingAs($admin)->post(route('verification.decision', $submission), ['action' => 'approve'])->assertRedirect();
        $this->actingAs($admin)->get('/dashboard?event='.$station->election_id.'&contest='.$contest->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.submitted', 1)
                ->where('stats.verified', 1)
                ->where('demoPreview.votes.valid', 300)
                ->where('demoPreview.votes.invalid', 7)
                ->where('demoPreview.candidates.0.votes', 120));
    }

    public function test_contest_and_region_filters_are_isolated(): void
    {
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $election = Election::query()->where('name', 'Simulasi Pilkada Papua 2026 (Data Demo)')->firstOrFail();
        $firstContest = $election->contests()->firstOrFail();
        $otherContest = $election->contests()->create(['name' => 'Kontes Eksekutif Kedua', 'type' => 'executive', 'ballot_schema_version' => 'candidate-v1', 'status' => 'active']);
        $otherContest->participants()->create(['ballot_number' => '01', 'display_name' => 'Paslon Uji A', 'display_order' => 1, 'active' => true]);

        $this->actingAs($admin)->get('/dashboard?event='.$election->id.'&contest='.$otherContest->id)
            ->assertInertia(fn (Assert $page) => $page->where('selectedContestId', $otherContest->id)->has('contestOptions', 2)->has('demoPreview.candidates', 1)->where('demoPreview.stats.verified', 0));
        $this->actingAs($admin)->get('/dashboard?event='.$election->id.'&contest='.$firstContest->id)
            ->assertInertia(fn (Assert $page) => $page->has('demoPreview.candidates', 3));

        $station = ElectionPollingStation::query()->where('election_id', $election->id)->with('pollingStation.region.parent.parent')->firstOrFail();
        $village = $station->pollingStation->region;
        $district = $village->parent;
        $regency = $district?->parent ?? $district;
        $this->assertInstanceOf(Region::class, $regency);
        $this->actingAs($admin)->get('/dashboard?event='.$election->id.'&contest='.$firstContest->id.'&region='.$regency->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.scope.id', $regency->id)
                ->where('regionFilters.selected.regency_id', $regency->id)
                ->has('regionFilters.districts')
                ->where('stats.total', 10));

        $expectedVillageTps = ElectionPollingStation::query()->where('election_id', $election->id)
            ->whereHas('pollingStation', fn ($query) => $query->where('region_id', $village->id))->count();
        $this->actingAs($admin)->get('/dashboard?event='.$election->id.'&contest='.$firstContest->id.'&region='.$village->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.scope.id', $village->id)
                ->where('regionFilters.selected.regency_id', $regency->id)
                ->where('regionFilters.selected.district_id', $district->id)
                ->where('regionFilters.selected.village_id', $village->id)
                ->where('stats.total', $expectedVillageTps));
    }

    public function test_map_has_dedicated_page_for_admin_and_coordinator_but_not_witness(): void
    {
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $coordinator = User::query()->where('email', 'coordinator.jayapura@electcount.test')->firstOrFail();
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $election = Election::query()->whereHas('eventAssignments', fn ($query) => $query->where('user_id', $coordinator->id))->firstOrFail();

        $this->actingAs($admin)->get(route('map.index', ['event' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Map/Index')->where('event.id', $election->id)->has('demoPreview.regions'));

        $this->actingAs($coordinator)->get(route('map.index', ['event' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Map/Index')->where('event.id', $election->id));

        $this->actingAs($witness)->get(route('map.index', ['event' => $election->id]))->assertForbidden();
    }

    public function test_monitoring_has_dedicated_scoped_page_for_admin_and_coordinator(): void
    {
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $coordinator = User::query()->where('email', 'coordinator.jayapura@electcount.test')->firstOrFail();
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $election = Election::query()->whereHas('eventAssignments', fn ($query) => $query->where('user_id', $coordinator->id))->firstOrFail();
        $eventStation = ElectionPollingStation::query()->where('election_id', $election->id)->with('pollingStation')->firstOrFail();
        $eventStation->pollingStation->update(['location_label' => 'TPS 001']);

        $this->actingAs($admin)->get(route('monitoring.index', ['event' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Monitoring/Index')
                ->where('event.id', $election->id)
                ->where('demoPreview.monitoring.0.tps', 'TPS 001'));

        $this->actingAs($coordinator)->get(route('monitoring.index', ['event' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Monitoring/Index')->where('event.id', $election->id));

        $this->actingAs($witness)->get(route('monitoring.index', ['event' => $election->id]))->assertForbidden();
    }
}
