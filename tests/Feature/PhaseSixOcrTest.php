<?php

namespace Tests\Feature;

use App\Jobs\ProcessEvidenceOcr;
use App\Models\User;
use App\Models\VoteSubmission;
use App\Services\TesseractOcr;
use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PhaseSixOcrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->seed(DemoScenarioSeeder::class);
    }

    public function test_ocr_suggestion_requires_human_confirmation_before_submit(): void
    {
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $this->actingAs($witness)->post(route('reports.store'), $this->payload($witness))->assertSessionHasNoErrors();
        $submission = VoteSubmission::query()->with(['evidence.ocrJob', 'eventTps.election'])->latest('id')->firstOrFail();
        $record = $submission->evidence->first()->ocrJob;
        Queue::assertPushed(ProcessEvidenceOcr::class, fn ($job) => $job->ocrJobId === $record->id);

        $ocr = Mockery::mock(TesseractOcr::class);
        $ocr->shouldReceive('extract')->once()->andReturn(['engine_version' => 'tesseract fixture', 'confidence' => 91.5, 'raw_text' => '120 100 80 5', 'extracted_data' => ['numbers' => [120, 100, 80, 5]]]);
        (new ProcessEvidenceOcr($record->id))->handle($ocr);

        $this->actingAs($witness)->get(route('reports.create', ['event' => $submission->election_id, 'contest' => $submission->election_contest_id, 'tps' => $submission->election_polling_station_id, 'draft' => $submission->id]))
            ->assertInertia(fn (Assert $page) => $page->where('ocrSuggestion.id', $record->id)->where('ocrSuggestion.numbers', [120, 100, 80, 5]));

        $submit = $this->payload($witness, $submission, false);
        $submit['intent'] = 'submit';
        $submit['ocr_job_id'] = $record->id;
        $submit['ocr_confirmed'] = false;
        $this->actingAs($witness)->post(route('reports.store'), $submit)->assertSessionHasErrors('ocr_confirmed');
        $submit['ocr_confirmed'] = true;
        $this->actingAs($witness)->post(route('reports.store'), $submit)->assertSessionHasNoErrors();
        $this->assertSame('submitted', $submission->fresh()->status->value);
        $this->assertTrue($submission->totals()->firstOrFail()->extra_fields['ocr_confirmed']);
    }

    public function test_ocr_failure_is_retryable_and_manual_submission_still_works(): void
    {
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $this->actingAs($witness)->post(route('reports.store'), $this->payload($witness))->assertSessionHasNoErrors();
        $submission = VoteSubmission::query()->with('evidence.ocrJob')->latest('id')->firstOrFail();
        $record = $submission->evidence->first()->ocrJob;
        $ocr = Mockery::mock(TesseractOcr::class);
        $ocr->shouldReceive('extract')->once()->andThrow(new RuntimeException('Binary Tesseract tidak tersedia'));
        (new ProcessEvidenceOcr($record->id))->handle($ocr);
        $this->assertSame('failed', $record->fresh()->status);

        Queue::fake();
        $this->actingAs($witness)->post(route('reports.ocr.store', $submission->evidence->first()))->assertSessionHasNoErrors();
        Queue::assertPushed(ProcessEvidenceOcr::class);
        $other = User::query()->where('email', 'saksi02@electcount.test')->firstOrFail();
        $this->actingAs($other)->post(route('reports.ocr.store', $submission->evidence->first()))->assertNotFound();

        $manual = $this->payload($witness, $submission, false);
        $manual['intent'] = 'submit';
        $this->actingAs($witness)->post(route('reports.store'), $manual)->assertSessionHasNoErrors();
        $this->assertSame('submitted', $submission->fresh()->status->value);
    }

    private function payload(User $witness, ?VoteSubmission $submission = null, bool $withEvidence = true): array
    {
        $station = $submission?->eventTps ?? $witness->tpsAssignments()->with('electionPollingStation.election')->firstOrFail()->electionPollingStation;
        $contest = $station->election->contests()->with('participants')->firstOrFail();

        return ['election_id' => $station->election_id, 'election_contest_id' => $contest->id, 'election_polling_station_id' => $station->id, 'intent' => 'draft', 'idempotency_key' => $submission?->idempotency_key ?? (string) Str::uuid(), 'items' => $contest->participants->values()->map(fn ($participant, $index) => ['participant_id' => $participant->id, 'votes' => 120 - ($index * 20)])->all(), 'invalid_votes' => 5, 'evidence' => $withEvidence ? [UploadedFile::fake()->image('c-hasil.jpg', 1280, 960)] : []];
    }
}
