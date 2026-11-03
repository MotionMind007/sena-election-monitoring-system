<?php

namespace Tests\Feature;

use App\Models\ElectionPollingStation;
use App\Models\User;
use App\Models\VerifiedResult;
use App\Models\VoteSubmission;
use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseFourVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DemoScenarioSeeder::class);
    }

    public function test_coordinator_can_recommend_witness_report_but_not_own_proxy_report(): void
    {
        $witness = $this->user('saksi01@electcount.test');
        $coordinator = $this->user('coordinator.jayapura@electcount.test');
        $submission = $this->submit($witness);
        $this->actingAs($coordinator)->post(route('verification.review', $submission), ['action' => 'recommend', 'notes' => 'Angka sesuai bukti'])->assertRedirect();
        $this->assertSame('recommended', $submission->fresh()->status->value);
        $this->assertDatabaseHas('coordinator_reviews', ['vote_submission_id' => $submission->id, 'reviewer_id' => $coordinator->id, 'action' => 'recommend']);

        $own = $this->submit($coordinator, $submission->eventTps, ['source_name' => 'Saksi lapangan', 'source_method' => 'raw_photo']);
        $this->actingAs($coordinator)->post(route('verification.review', $own), ['action' => 'recommend'])->assertSessionHasErrors('action');
    }

    public function test_only_super_admin_final_approves_and_cannot_approve_own_proxy(): void
    {
        $witness = $this->user('saksi01@electcount.test');
        $coordinator = $this->user('coordinator.jayapura@electcount.test');
        $admin = $this->user('admin@electcount.test');
        $submission = $this->submit($witness);
        $this->actingAs($coordinator)->post(route('verification.decision', $submission), ['action' => 'approve'])->assertForbidden();
        $this->actingAs($admin)->post(route('verification.decision', $submission), ['action' => 'approve'])->assertRedirect();
        $this->assertSame('approved', $submission->fresh()->status->value);
        $this->assertDatabaseHas('verified_results', ['source_submission_id' => $submission->id, 'active' => true, 'verified_by' => $admin->id]);
        $this->assertDatabaseCount('verified_result_items', 3);

        $own = $this->submit($admin, null, ['source_name' => 'Saksi fisik', 'source_method' => 'physical_form']);
        $this->actingAs($admin)->post(route('verification.decision', $own), ['action' => 'approve'])->assertSessionHasErrors('action');
    }

    public function test_approval_resolves_conflict_and_correction_supersedes_active_result(): void
    {
        $witness = $this->user('saksi01@electcount.test');
        $coordinator = $this->user('coordinator.jayapura@electcount.test');
        $admin = $this->user('admin@electcount.test');
        $first = $this->submit($witness);
        $conflict = $this->submit($coordinator, $first->eventTps, ['source_name' => 'Saksi 001', 'source_method' => 'raw_photo']);
        $this->actingAs($admin)->post(route('verification.decision', $first), ['action' => 'approve']);
        $this->assertSame('conflict', $conflict->fresh()->status->value);
        $old = VerifiedResult::query()->where('active', true)->firstOrFail();

        $correction = $this->submit($witness, $first->eventTps);
        $this->actingAs($admin)->post(route('verification.decision', $correction), ['action' => 'approve']);
        $new = VerifiedResult::query()->where('active', true)->firstOrFail();
        $this->assertNotSame($old->id, $new->id);
        $this->assertFalse($old->fresh()->active);
        $this->assertSame($old->id, $new->supersedes_id);
        $this->assertSame(1, VerifiedResult::query()->where('active', true)->count());
    }

    public function test_admin_can_request_revision_or_reject_with_reason(): void
    {
        $admin = $this->user('admin@electcount.test');
        $witness = $this->user('saksi01@electcount.test');
        $revision = $this->submit($witness);
        $this->actingAs($admin)->post(route('verification.decision', $revision), ['action' => 'revision_requested', 'reason' => 'Foto kurang jelas'])->assertRedirect();
        $this->assertSame('revision_requested', $revision->fresh()->status->value);
        $rejected = $this->submit($witness, $revision->eventTps);
        $this->actingAs($admin)->post(route('verification.decision', $rejected), ['action' => 'reject', 'reason' => 'Dokumen tidak sesuai'])->assertRedirect();
        $this->assertSame('rejected', $rejected->fresh()->status->value);
        $this->assertDatabaseCount('approval_actions', 2);
    }

    public function test_processed_submission_cannot_be_mutated_by_reusing_its_idempotency_key(): void
    {
        $admin = $this->user('admin@electcount.test');
        $witness = $this->user('saksi01@electcount.test');
        $submission = $this->submit($witness);
        $this->actingAs($admin)->post(route('verification.decision', $submission), ['action' => 'approve']);

        $originalItems = $submission->items()->orderBy('contest_participant_id')->pluck('votes')->all();
        $originalTotals = $submission->totals()->firstOrFail()->only(['valid_votes', 'invalid_votes', 'total_votes']);
        $originalEvidenceCount = $submission->evidence()->count();
        $payload = $this->payloadFor($witness, $submission->eventTps, [
            'idempotency_key' => $submission->idempotency_key,
            'intent' => 'draft',
        ]);
        foreach ($payload['items'] as &$item) {
            $item['votes'] = 999;
        }
        unset($item);

        $this->actingAs($witness)->post(route('reports.store'), $payload)->assertSessionHasErrors('idempotency_key');

        $this->assertSame('approved', $submission->fresh()->status->value);
        $this->assertSame($originalItems, $submission->items()->orderBy('contest_participant_id')->pluck('votes')->all());
        $this->assertSame($originalTotals, $submission->totals()->firstOrFail()->only(['valid_votes', 'invalid_votes', 'total_votes']));
        $this->assertSame($originalEvidenceCount, $submission->evidence()->count());
        $this->assertDatabaseCount('verified_results', 1);
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function submit(User $actor, ?ElectionPollingStation $station = null, array $extra = []): VoteSubmission
    {
        $station ??= $actor->tpsAssignments()->with('electionPollingStation.election')->first()?->electionPollingStation ?? ElectionPollingStation::query()->with('election')->firstOrFail();
        $payload = $this->payloadFor($actor, $station, $extra);
        $this->actingAs($actor)->post(route('reports.store'), $payload)->assertRedirect();

        return VoteSubmission::query()->latest('id')->with(['eventTps.election'])->firstOrFail();
    }

    private function payloadFor(User $actor, ElectionPollingStation $station, array $extra = []): array
    {
        $contest = $station->election->contests()->with('participants')->firstOrFail();

        return ['election_id' => $station->election_id, 'election_contest_id' => $contest->id, 'election_polling_station_id' => $station->id, 'intent' => 'submit', 'idempotency_key' => (string) Str::uuid(), 'items' => $contest->participants->values()->map(fn ($p, $i) => ['participant_id' => $p->id, 'votes' => 100 - $i * 10])->all(), 'invalid_votes' => 5, 'evidence' => [UploadedFile::fake()->image('bukti.jpg', 1280, 960)], ...$extra];
    }
}
