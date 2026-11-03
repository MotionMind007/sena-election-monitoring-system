<?php

namespace Tests\Feature;

use App\Actions\Elections\CreateElection;
use App\Enums\RegionLevel;
use App\Enums\UserRole;
use App\Models\ElectionPollingStation;
use App\Models\Party;
use App\Models\PollingStation;
use App\Models\RecapComparison;
use App\Models\Region;
use App\Models\User;
use App\Models\VerifiedResult;
use App\Models\VoteSubmission;
use App\Services\RecapAggregate;
use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductionPhaseTwoRecapComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DemoScenarioSeeder::class);
    }

    public function test_preview_aggregates_only_active_approved_tps_results(): void
    {
        [$admin, $witness, $station, $contest] = $this->approveReport([120, 100, 80], 7);
        $district = $station->pollingStation->region->parent;

        $this->actingAs($admin)->getJson(route('recap-comparisons.preview', [
            'event' => $station->election_id,
            'contest' => $contest->id,
            'region' => $district->id,
        ]))->assertOk()
            ->assertJsonPath('verified_tps_count', 1)
            ->assertJsonPath('automatic.valid_votes', 300)
            ->assertJsonPath('automatic.invalid_votes', 7)
            ->assertJsonPath('automatic.total_votes', 307)
            ->assertJsonCount(3, 'items')
            ->assertJsonPath('items.0.automatic_votes', 120)
            ->assertJsonPath('latest', null);

        $this->assertSame('saksi01@electcount.test', $witness->email);
    }

    public function test_report_picker_exposes_only_role_scoped_recap_levels(): void
    {
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $coordinator = User::query()->where('email', 'coordinator.jayapura@electcount.test')->firstOrFail();
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $event = $witness->tpsAssignments()->with('electionPollingStation.election')->firstOrFail()->electionPollingStation->election;

        $this->actingAs($admin)->get(route('reports.index', ['event' => $event->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewRecaps', true)
                ->where('canManageRecaps', true)
                ->has('recapRegions.district', 2)
                ->has('recapRegions.regency', 2)
                ->has('recapRegions.province', 1));

        $this->actingAs($coordinator)->get(route('reports.index', ['event' => $event->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewRecaps', true)
                ->where('canManageRecaps', false)
                ->has('recapRegions.regency', 1)
                ->has('recapRegions.province', 0));

        $this->actingAs($witness)->get(route('reports.index', ['event' => $event->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewRecaps', false)
                ->where('canManageRecaps', false)
                ->has('recapRegions.district', 0)
                ->has('recapRegions.regency', 0)
                ->has('recapRegions.province', 0));
    }

    public function test_super_admin_stores_immutable_comparison_and_detects_per_participant_difference(): void
    {
        [$admin, , $station, $contest] = $this->approveReport([120, 100, 80], 7);
        $district = $station->pollingStation->region->parent;
        $key = (string) Str::uuid();
        $payload = $this->comparisonPayload($station, $contest, $district->id, $key, [120, 100, 80], 7);

        $this->actingAs($admin)->post(route('recap-comparisons.store'), $payload)->assertRedirect(route('reports.index', ['event' => $station->election_id]));
        $matching = RecapComparison::query()->with('items')->firstOrFail();
        $this->assertFalse($matching->has_discrepancy);
        $this->assertSame(1, $matching->version);
        $this->assertSame(307, $matching->automatic_total_votes);
        $this->assertSame(307, $matching->official_total_votes);
        $this->assertSame([0, 0, 0], $matching->items->pluck('delta')->all());
        Storage::disk('local')->assertExists($matching->evidence_key);
        $this->assertDatabaseHas('audit_logs', ['action' => 'recap_comparison.created', 'object_id' => (string) $matching->id]);

        $payload['evidence'] = UploadedFile::fake()->image('retry.jpg');
        $this->actingAs($admin)->post(route('recap-comparisons.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('recap_comparisons', 1);

        $different = $this->comparisonPayload($station, $contest, $district->id, (string) Str::uuid(), [125, 100, 80], 7);
        $this->actingAs($admin)->post(route('recap-comparisons.store'), $different)->assertRedirect();
        $versionTwo = RecapComparison::query()->where('version', 2)->with('items')->firstOrFail();
        $this->assertTrue($versionTwo->has_discrepancy);
        $this->assertSame(5, $versionTwo->items->firstWhere('contest_participant_id', $contest->participants->first()->id)->delta);
        $this->assertSame(312, $versionTwo->official_total_votes);

        $matching->refresh();
        $this->assertFalse($matching->has_discrepancy);
        $this->assertSame(307, $matching->official_total_votes);
    }

    public function test_new_approval_does_not_mutate_snapshot_and_current_difference_is_visible(): void
    {
        [$admin, $witness, $station, $contest] = $this->approveReport([120, 100, 80], 7);
        $district = $station->pollingStation->region->parent;
        $this->actingAs($admin)->post(route('recap-comparisons.store'), $this->comparisonPayload(
            $station, $contest, $district->id, (string) Str::uuid(), [120, 100, 80], 7
        ))->assertRedirect();
        $snapshot = RecapComparison::query()->firstOrFail();

        $submission = $this->submitReport($witness, $station, $contest, [150, 100, 80], 7);
        $this->actingAs($admin)->post(route('verification.decision', $submission), ['action' => 'approve'])->assertRedirect();

        $snapshot->refresh();
        $this->assertSame(300, $snapshot->automatic_valid_votes);
        $this->assertSame(307, $snapshot->automatic_total_votes);

        $this->actingAs($admin)->getJson(route('recap-comparisons.preview', [
            'event' => $station->election_id,
            'contest' => $contest->id,
            'region' => $district->id,
        ]))->assertOk()
            ->assertJsonPath('automatic.valid_votes', 330)
            ->assertJsonPath('automatic.total_votes', 337)
            ->assertJsonPath('latest.snapshot_stale', true)
            ->assertJsonPath('latest.current_has_discrepancy', true)
            ->assertJsonPath('latest.current_delta_total', -30);

        $this->actingAs($admin)->get('/dashboard?event='.$station->election_id.'&contest='.$contest->id)
            ->assertInertia(fn ($page) => $page->where('demoPreview.votes.valid', 330)->where('demoPreview.votes.total', 337));
    }

    public function test_scope_and_role_protect_preview_store_and_private_evidence(): void
    {
        [$admin, , $station, $contest] = $this->approveReport([120, 100, 80], 7);
        $district = $station->pollingStation->region->parent;
        $payload = $this->comparisonPayload($station, $contest, $district->id, (string) Str::uuid(), [120, 100, 80], 7);
        $this->actingAs($admin)->post(route('recap-comparisons.store'), $payload)->assertRedirect();
        $comparison = RecapComparison::query()->firstOrFail();

        $coordinator = User::query()->where('email', 'coordinator.jayapura@electcount.test')->firstOrFail();
        $this->actingAs($coordinator)->getJson(route('recap-comparisons.preview', [
            'event' => $station->election_id, 'contest' => $contest->id, 'region' => $district->id,
        ]))->assertOk();
        $this->actingAs($coordinator)->post(route('recap-comparisons.store'), $payload)->assertForbidden();
        $this->actingAs($coordinator)->get(route('recap-comparisons.evidence', $comparison))->assertOk()->assertDownload();

        $outsideCoordinator = User::query()->where('email', 'coordinator.kota@electcount.test')->firstOrFail();
        $this->actingAs($outsideCoordinator)->getJson(route('recap-comparisons.preview', [
            'event' => $station->election_id, 'contest' => $contest->id, 'region' => $district->id,
        ]))->assertNotFound();
        $this->actingAs($outsideCoordinator)->get(route('recap-comparisons.evidence', $comparison))->assertNotFound();

        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $this->actingAs($witness)->getJson(route('recap-comparisons.preview', [
            'event' => $station->election_id, 'contest' => $contest->id, 'region' => $district->id,
        ]))->assertForbidden();
    }

    public function test_comparison_rejects_incomplete_participant_set_and_cross_event_context(): void
    {
        [$admin, , $station, $contest] = $this->approveReport([120, 100, 80], 7);
        $district = $station->pollingStation->region->parent;
        $payload = $this->comparisonPayload($station, $contest, $district->id, (string) Str::uuid(), [120, 100, 80], 7);
        array_pop($payload['items']);
        $this->actingAs($admin)->post(route('recap-comparisons.store'), $payload)->assertSessionHasErrors('items');
        $this->assertDatabaseCount('recap_comparisons', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('recap-evidence'));

        $otherStation = ElectionPollingStation::query()->where('election_id', '!=', $station->election_id)->with('election.contests')->firstOrFail();
        $payload = $this->comparisonPayload($station, $contest, $district->id, (string) Str::uuid(), [120, 100, 80], 7);
        $payload['election_id'] = $otherStation->election_id;
        $this->actingAs($admin)->post(route('recap-comparisons.store'), $payload)->assertSessionHasErrors('region_id');
        $this->assertDatabaseCount('recap_comparisons', 0);
    }

    public function test_pileg_district_snapshot_includes_direct_party_and_candidate_dimensions_once(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $province = Region::query()->create(['code' => 'P-P2', 'name' => 'Provinsi P2', 'level' => RegionLevel::Province, 'source' => 'manual']);
        $regency = Region::query()->create(['code' => 'R-P2', 'name' => 'Kabupaten P2', 'level' => RegionLevel::Regency, 'parent_id' => $province->id, 'source' => 'manual']);
        $district = Region::query()->create(['code' => 'D-P2', 'name' => 'Distrik P2', 'level' => RegionLevel::District, 'parent_id' => $regency->id, 'source' => 'manual']);
        $village = Region::query()->create(['code' => 'V-P2', 'name' => 'Kampung P2', 'level' => RegionLevel::Village, 'parent_id' => $district->id, 'source' => 'manual']);
        PollingStation::query()->create(['region_id' => $village->id, 'code' => 'TPS-P2-001', 'number' => 1, 'location_label' => 'TPS 001', 'source' => 'manual', 'created_by' => $admin->id]);
        $event = app(CreateElection::class)->handle($admin, ['name' => 'Pileg P2', 'year' => 2033, 'type' => 'pileg', 'scope_region_id' => $province->id]);
        $party = Party::query()->create(['name' => 'Partai P2', 'abbreviation' => 'PP2', 'active' => true]);
        $this->actingAs($admin)->post(route('admin.electoral-districts.store', $event), ['name' => 'Dapil P2', 'region_ids' => [$district->id]])->assertSessionHasNoErrors();
        $dapil = $event->electoralDistricts()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.election-parties.store', $event), ['party_id' => $party->id, 'ballot_number' => '8', 'electoral_district_ids' => [$dapil->id]])->assertSessionHasNoErrors();
        $eventParty = $event->electionParties()->firstOrFail();
        $contest = $event->contests()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.participants.store', $contest), [
            'ballot_number' => '1', 'display_name' => 'Caleg P2', 'display_order' => 1, 'active' => true,
            'election_party_id' => $eventParty->id, 'electoral_district_id' => $dapil->id,
        ])->assertSessionHasNoErrors();

        $station = $event->pollingStations()->firstOrFail();
        $submitter = User::factory()->role(UserRole::Witness)->create();
        $submission = VoteSubmission::query()->create([
            'election_id' => $event->id, 'election_contest_id' => $contest->id, 'election_polling_station_id' => $station->id,
            'submitter_id' => $submitter->id, 'status' => 'approved', 'entry_mode' => 'direct', 'idempotency_key' => Str::uuid(), 'version' => 1,
        ]);
        $submission->totals()->create(['valid_votes' => 100, 'invalid_votes' => 5, 'total_votes' => 105]);
        $verified = VerifiedResult::query()->create([
            'election_id' => $event->id, 'election_contest_id' => $contest->id, 'election_polling_station_id' => $station->id,
            'source_submission_id' => $submission->id, 'active' => true, 'verified_at' => now(), 'verified_by' => $admin->id,
        ]);
        $partyParticipant = $contest->participants()->where('participant_type', 'party')->firstOrFail();
        $candidate = $contest->participants()->where('participant_type', 'candidate')->firstOrFail();
        $verified->items()->create(['contest_participant_id' => $partyParticipant->id, 'votes' => 40, 'ballot_dimension' => 'party']);
        $verified->items()->create(['contest_participant_id' => $candidate->id, 'votes' => 60, 'ballot_dimension' => 'candidate']);

        $snapshot = app(RecapAggregate::class)->build($event, $contest, $district);
        $this->assertSame(100, $snapshot['valid_votes']);
        $this->assertSame(5, $snapshot['invalid_votes']);
        $this->assertSame(105, $snapshot['total_votes']);
        $this->assertSame(['party', 'candidate'], $snapshot['items']->pluck('ballot_dimension')->sortDesc()->values()->all());
        $this->assertSame([40, 60], $snapshot['items']->pluck('automatic_votes')->sort()->values()->all());
    }

    private function approveReport(array $votes, int $invalidVotes): array
    {
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $station = $witness->tpsAssignments()->with('electionPollingStation.election', 'electionPollingStation.pollingStation.region.parent')->firstOrFail()->electionPollingStation;
        $contest = $station->election->contests()->with('participants')->firstOrFail();
        $submission = $this->submitReport($witness, $station, $contest, $votes, $invalidVotes);
        $this->actingAs($admin)->post(route('verification.decision', $submission), ['action' => 'approve'])->assertRedirect();

        return [$admin, $witness, $station, $contest];
    }

    private function submitReport(User $witness, ElectionPollingStation $station, $contest, array $votes, int $invalidVotes): VoteSubmission
    {
        $this->actingAs($witness)->post(route('reports.store'), [
            'election_id' => $station->election_id,
            'election_contest_id' => $contest->id,
            'election_polling_station_id' => $station->id,
            'intent' => 'submit',
            'idempotency_key' => (string) Str::uuid(),
            'items' => $contest->participants->values()->map(fn ($participant, $index) => ['participant_id' => $participant->id, 'votes' => $votes[$index]])->all(),
            'invalid_votes' => $invalidVotes,
            'evidence' => [UploadedFile::fake()->image('c-hasil.jpg', 1280, 960)],
        ])->assertRedirect();

        return VoteSubmission::query()->latest('id')->firstOrFail();
    }

    private function comparisonPayload(ElectionPollingStation $station, $contest, int $regionId, string $key, array $votes, int $invalidVotes): array
    {
        return [
            'election_id' => $station->election_id,
            'election_contest_id' => $contest->id,
            'region_id' => $regionId,
            'idempotency_key' => $key,
            'items' => $contest->participants->values()->map(fn ($participant, $index) => ['participant_id' => $participant->id, 'official_votes' => $votes[$index]])->all(),
            'official_invalid_votes' => $invalidVotes,
            'evidence' => UploadedFile::fake()->image('rekap-resmi.jpg'),
            'notes' => 'Dokumen rekap resmi untuk pengujian.',
        ];
    }
}
