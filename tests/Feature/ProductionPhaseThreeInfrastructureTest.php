<?php

namespace Tests\Feature;

use App\Actions\Audit\WriteAuditLog;
use App\Actions\Reporting\SaveVoteSubmission;
use App\Jobs\ProcessEvidenceOcr;
use App\Jobs\RecordQueueHeartbeat;
use App\Models\User;
use App\Models\VoteSubmission;
use App\Services\TesseractOcr;
use App\Support\ProductionConfiguration;
use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProductionPhaseThreeInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Queue::fake();
        config()->set('filesystems.evidence', 's3');
        $this->seed(DemoScenarioSeeder::class);
    }

    public function test_tps_evidence_uses_private_configured_disk_and_streams_to_ocr_temporary_file(): void
    {
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $payload = $this->payload($witness);
        $payload['evidence'] = [UploadedFile::fake()->image('c-hasil.jpg', 1280, 960)];

        $this->actingAs($witness)->post(route('reports.store'), $payload)->assertRedirect();

        $submission = VoteSubmission::query()->with('evidence.ocrJob')->firstOrFail();
        $evidence = $submission->evidence->firstOrFail();
        $this->assertSame('s3', $evidence->disk);
        Storage::disk('s3')->assertExists($evidence->storage_key);

        $temporaryPath = null;
        $ocr = Mockery::mock(TesseractOcr::class);
        $ocr->shouldReceive('extract')->once()->withArgs(function (string $path) use (&$temporaryPath): bool {
            $temporaryPath = $path;

            return is_file($path) && filesize($path) > 0;
        })->andReturn(['engine_version' => 'fixture', 'confidence' => 90.0, 'raw_text' => '10 20 30 1', 'extracted_data' => ['numbers' => [10, 20, 30, 1]]]);

        (new ProcessEvidenceOcr($evidence->ocrJob->id))->handle($ocr);

        $this->assertNotNull($temporaryPath);
        $this->assertFileDoesNotExist($temporaryPath);
        $this->assertSame('completed', $evidence->ocrJob->fresh()->status);
    }

    public function test_failed_database_transaction_removes_uploaded_object(): void
    {
        $witness = User::query()->where('email', 'saksi01@electcount.test')->firstOrFail();
        $payload = $this->payload($witness);
        $file = UploadedFile::fake()->image('rollback.jpg', 1280, 960);
        $audit = Mockery::mock(WriteAuditLog::class);
        $audit->shouldReceive('handle')->once()->andThrow(new RuntimeException('simulated audit failure'));

        try {
            (new SaveVoteSubmission($audit))->handle(
                $witness,
                $witness->tpsAssignments()->with('electionPollingStation.election.contests.participants')->firstOrFail()->electionPollingStation->election->contests->first(),
                $witness->tpsAssignments()->with('electionPollingStation')->firstOrFail()->electionPollingStation,
                $payload,
                [$file],
            );
            $this->fail('Expected simulated transaction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated audit failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('vote_submissions', 0);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_readiness_requires_fresh_worker_and_scheduler_heartbeats_when_enabled(): void
    {
        Storage::fake('local');
        config()->set(['filesystems.evidence' => 'local', 'health.require_background_heartbeats' => true]);
        Cache::forget('health:scheduler:last_seen');
        Cache::forget('health:queue:last_seen');

        $this->getJson('/ready')->assertServiceUnavailable()->assertExactJson(['status' => 'unavailable']);

        Cache::put('health:scheduler:last_seen', now()->timestamp, 600);
        (new RecordQueueHeartbeat)->handle();
        $this->getJson('/ready')->assertOk()->assertExactJson(['status' => 'ready']);
    }

    public function test_safe_production_service_configuration_is_accepted(): void
    {
        $keys = [
            'app.debug', 'app.url', 'app.key', 'database.default', 'database.connections.pgsql.sslmode', 'cache.default', 'cache.limiter',
            'queue.default', 'queue.connections.redis.after_commit', 'session.driver', 'session.encrypt',
            'session.secure', 'filesystems.evidence', 'filesystems.disks.s3.bucket', 'filesystems.disks.s3.region',
            'filesystems.disks.s3.options.ServerSideEncryption', 'health.require_background_heartbeats',
            'malware-scan.enabled', 'malware-scan.host',
        ];
        $original = collect($keys)->mapWithKeys(fn (string $key) => [$key => config($key)])->all();

        try {
            config()->set([
                'app.debug' => false,
                'app.url' => 'https://sena.example.id',
                'app.key' => 'base64:'.base64_encode(random_bytes(32)),
                'database.default' => 'pgsql',
                'database.connections.pgsql.sslmode' => 'require',
                'cache.default' => 'redis',
                'cache.limiter' => 'redis',
                'queue.default' => 'redis',
                'queue.connections.redis.after_commit' => true,
                'session.driver' => 'redis',
                'session.encrypt' => true,
                'session.secure' => true,
                'filesystems.evidence' => 's3',
                'filesystems.disks.s3.bucket' => 'private-evidence',
                'filesystems.disks.s3.region' => 'ap-southeast-2',
                'filesystems.disks.s3.options.ServerSideEncryption' => 'AES256',
                'health.require_background_heartbeats' => true,
                'malware-scan.enabled' => true,
                'malware-scan.host' => 'clamav',
            ]);

            $this->assertSame([], app(ProductionConfiguration::class)->errors());
        } finally {
            config()->set($original);
        }
    }

    private function payload(User $witness): array
    {
        $station = $witness->tpsAssignments()->with('electionPollingStation.election')->firstOrFail()->electionPollingStation;
        $contest = $station->election->contests()->with('participants')->firstOrFail();

        return [
            'election_id' => $station->election_id,
            'election_contest_id' => $contest->id,
            'election_polling_station_id' => $station->id,
            'intent' => 'submit',
            'idempotency_key' => (string) Str::uuid(),
            'items' => $contest->participants->values()->map(fn ($participant, $index) => [
                'participant_id' => $participant->id,
                'votes' => 100 - ($index * 10),
            ])->all(),
            'invalid_votes' => 3,
        ];
    }
}
