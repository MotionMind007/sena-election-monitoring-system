<?php

namespace Tests\Feature;

use App\Models\Election;
use App\Models\ElectionPollingStation;
use App\Models\SubmissionEvidence;
use App\Models\User;
use App\Models\VoteSubmission;
use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PhaseThreeReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DemoScenarioSeeder::class);
    }

    public function test_report_picker_exposes_event_participants_and_tps_hierarchy(): void
    {
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $event = Election::query()->where('type', 'pilkada')->firstOrFail();

        $this->actingAs($admin)->get(route('reports.index', ['event' => $event->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('event.id', $event->id)
                ->where('event.type', 'pilkada')
                ->has('contests.0.participants', 3)
                ->has('stations.0', fn (Assert $station) => $station
                    ->where('code', 'TPS 001')
                    ->hasAll(['village_id', 'village', 'district_id', 'district', 'regency_id', 'regency'])
                    ->etc()));
    }

    public function test_witness_submits_assigned_tps_with_private_evidence_and_idempotent_retry(): void
    {
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $eventTps = $witness->tpsAssignments()->with('electionPollingStation')->firstOrFail()->electionPollingStation;
        $contest = $eventTps->election->contests()->with('participants')->firstOrFail();
        $key = (string) Str::uuid();
        $payload = $this->payload($eventTps, $contest, $key, [120, 98, 44]);
        $payload['evidence'] = [UploadedFile::fake()->image('form-c1.jpg', 1280, 960)];

        $response = $this->actingAs($witness)->post(route('reports.store'), $payload);
        $submission = VoteSubmission::query()->firstOrFail();
        $response->assertRedirect(route('reports.show', $submission));
        $this->assertSame('submitted', $submission->status->value);
        $this->assertSame(1, $submission->version);
        $this->assertSame('direct', $submission->entry_mode);
        $this->assertSame(262, $submission->totals->valid_votes);
        $this->assertSame(269, $submission->totals->total_votes);
        $evidence = $submission->evidence()->firstOrFail();
        Storage::disk('local')->assertExists($evidence->storage_key);
        $this->assertNotNull($evidence->preview_key);
        Storage::disk('local')->assertExists($evidence->preview_key);
        $this->actingAs($witness)->get(route('reports.evidence', ['evidence' => $evidence, 'variant' => 'preview']))
            ->assertOk()
            ->assertHeader('content-type', 'image/webp');
        $this->assertDatabaseHas('audit_logs', ['action' => 'vote_submission.submitted', 'object_id' => (string) $submission->id]);

        $payload['evidence'] = [UploadedFile::fake()->image('retry.jpg', 1280, 960)];
        $this->actingAs($witness)->post(route('reports.store'), $payload)->assertRedirect(route('reports.show', $submission));
        $this->assertDatabaseCount('vote_submissions', 1);
        $this->assertDatabaseCount('submission_evidence', 1);
    }

    public function test_super_admin_can_submit_proxy_report_with_required_provenance(): void
    {
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $eventTps = ElectionPollingStation::query()->with('election')->firstOrFail();
        $contest = $eventTps->election->contests()->with('participants')->firstOrFail();
        $payload = $this->payload($eventTps, $contest, (string) Str::uuid(), [90, 80, 70]);
        $payload['evidence'] = [UploadedFile::fake()->image('fisik.png', 960, 1280)];

        $this->actingAs($admin)->post(route('reports.store'), $payload)
            ->assertSessionHasErrors(['source_name', 'source_method']);

        $payload['source_name'] = 'Saksi TPS 001';
        $payload['source_method'] = 'physical_form';
        $payload['source_notes'] = 'Form fisik diterima di sekretariat.';
        $this->actingAs($admin)->post(route('reports.store'), $payload)->assertRedirect();

        $submission = VoteSubmission::query()->firstOrFail();
        $this->assertSame('admin_proxy', $submission->entry_mode);
        $this->assertSame('Saksi TPS 001', $submission->source_name);
        $this->assertSame('physical_form', $submission->source_method);
    }

    public function test_report_accepts_exactly_one_high_resolution_evidence_photo(): void
    {
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $eventTps = $witness->tpsAssignments()->with('electionPollingStation')->firstOrFail()->electionPollingStation;
        $contest = $eventTps->election->contests()->with('participants')->firstOrFail();

        $tooSmall = $this->payload($eventTps, $contest, (string) Str::uuid(), [1, 2, 3]);
        $tooSmall['evidence'] = [UploadedFile::fake()->image('buram.jpg', 640, 480)];
        $this->actingAs($witness)->post(route('reports.store'), $tooSmall)->assertSessionHasErrors('evidence');

        $tooMany = $this->payload($eventTps, $contest, (string) Str::uuid(), [1, 2, 3]);
        $tooMany['evidence'] = [
            UploadedFile::fake()->image('satu.jpg', 1280, 960),
            UploadedFile::fake()->image('dua.jpg', 1280, 960),
        ];
        $this->actingAs($witness)->post(route('reports.store'), $tooMany)->assertSessionHasErrors('evidence');
        $this->assertDatabaseCount('vote_submissions', 0);
    }

    public function test_report_is_rejected_when_required_malware_scanner_is_unavailable(): void
    {
        config()->set([
            'malware-scan.enabled' => true,
            'malware-scan.host' => '127.0.0.1',
            'malware-scan.port' => 1,
            'malware-scan.timeout_seconds' => 1,
        ]);

        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $eventTps = $witness->tpsAssignments()->with('electionPollingStation')->firstOrFail()->electionPollingStation;
        $contest = $eventTps->election->contests()->with('participants')->firstOrFail();
        $payload = $this->payload($eventTps, $contest, (string) Str::uuid(), [10, 20, 30]);
        $payload['evidence'] = [UploadedFile::fake()->image('scan-me.jpg', 1280, 960)];

        $this->actingAs($witness)->post(route('reports.store'), $payload)->assertSessionHasErrors('evidence');
        $this->assertDatabaseCount('vote_submissions', 0);
        Storage::disk('local')->assertDirectoryEmpty('evidence');
        $this->assertDatabaseHas('audit_logs', ['action' => 'evidence.malware_scan_rejected', 'actor_id' => $witness->id]);
    }

    public function test_scope_blocks_unassigned_tps_and_private_evidence(): void
    {
        $witness = User::query()->where('email', 'saksi02@electcount.test')->firstOrFail();
        $otherWitness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $eventTps = $otherWitness->tpsAssignments()->with('electionPollingStation')->firstOrFail()->electionPollingStation;
        $contest = $eventTps->election->contests()->with('participants')->firstOrFail();
        $payload = $this->payload($eventTps, $contest, (string) Str::uuid(), [1, 2, 3]);
        $payload['evidence'] = [UploadedFile::fake()->image('scope.jpg', 1280, 960)];

        $this->actingAs($witness)->post(route('reports.store'), $payload)->assertNotFound();

        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $payload['source_name'] = 'Saksi lapangan';
        $payload['source_method'] = 'raw_photo';
        $this->actingAs($admin)->post(route('reports.store'), $payload);
        $evidence = SubmissionEvidence::query()->firstOrFail();
        $this->actingAs($witness)->get(route('reports.evidence', $evidence))->assertNotFound();
        $this->actingAs($admin)->get(route('reports.evidence', $evidence))->assertOk()->assertHeader('cache-control', 'no-store, private');
    }

    public function test_draft_can_be_completed_without_creating_a_duplicate_submission(): void
    {
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $eventTps = $witness->tpsAssignments()->with('electionPollingStation')->firstOrFail()->electionPollingStation;
        $contest = $eventTps->election->contests()->with('participants')->firstOrFail();
        $key = (string) Str::uuid();
        $payload = $this->payload($eventTps, $contest, $key, [20, 30, 40]);
        $payload['intent'] = 'draft';

        $this->actingAs($witness)->post(route('reports.store'), $payload)->assertRedirect();
        $this->assertSame('draft', VoteSubmission::query()->firstOrFail()->status->value);

        $payload['intent'] = 'submit';
        $payload['evidence'] = [UploadedFile::fake()->image('final.jpg', 1280, 960)];
        $this->actingAs($witness)->post(route('reports.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('vote_submissions', 1);
        $this->assertSame('submitted', VoteSubmission::query()->firstOrFail()->status->value);
    }

    public function test_new_idempotency_key_requires_an_explicit_correction_and_invalid_file_is_rejected(): void
    {
        $admin = User::query()->where('email', 'admin@electcount.test')->firstOrFail();
        $eventTps = ElectionPollingStation::query()->with('election')->firstOrFail();
        $contest = $eventTps->election->contests()->with('participants')->firstOrFail();

        $first = $this->payload($eventTps, $contest, (string) Str::uuid(), [10, 20, 30]);
        $first += ['source_name' => 'Saksi TPS', 'source_method' => 'raw_photo'];
        $first['evidence'] = [UploadedFile::fake()->image('bukti-1.webp', 1280, 960)];
        $this->actingAs($admin)->post(route('reports.store'), $first)->assertRedirect();
        $original = VoteSubmission::query()->firstOrFail();

        $second = $this->payload($eventTps, $contest, (string) Str::uuid(), [20, 20, 30]);
        $second += ['source_name' => 'Saksi TPS', 'source_method' => 'raw_photo'];
        $second['evidence'] = [UploadedFile::fake()->image('bukti-2.webp', 1280, 960)];
        $this->actingAs($admin)->post(route('reports.store'), $second)->assertSessionHasErrors('correction_of_submission_id');
        $this->assertDatabaseCount('vote_submissions', 1);

        $second['correction_of_submission_id'] = $original->id;
        $this->actingAs($admin)->post(route('reports.store'), $second)->assertRedirect();

        $this->assertSame([1, 2], VoteSubmission::query()->orderBy('version')->pluck('version')->all());
        $this->assertDatabaseHas('vote_submissions', ['version' => 2, 'correction_of_submission_id' => $original->id]);

        $payload = $this->payload($eventTps, $contest, (string) Str::uuid(), [1, 1, 1]);
        $payload += ['source_name' => 'Saksi TPS', 'source_method' => 'raw_photo'];
        $payload['correction_of_submission_id'] = VoteSubmission::query()->latest('version')->value('id');
        $payload['evidence'] = [UploadedFile::fake()->create('bukti.pdf', 20, 'application/pdf')];
        $this->actingAs($admin)->post(route('reports.store'), $payload)->assertSessionHasErrors('evidence.0');
        $this->assertDatabaseCount('vote_submissions', 2);
    }

    private function payload(ElectionPollingStation $eventTps, $contest, string $key, array $votes): array
    {
        return [
            'election_id' => $eventTps->election_id,
            'election_contest_id' => $contest->id,
            'election_polling_station_id' => $eventTps->id,
            'intent' => 'submit',
            'idempotency_key' => $key,
            'items' => $contest->participants->values()->map(fn ($participant, $index) => ['participant_id' => $participant->id, 'votes' => $votes[$index]])->all(),
            'invalid_votes' => 7,
        ];
    }
}
