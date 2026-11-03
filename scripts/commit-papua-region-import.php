<?php

declare(strict_types=1);

use App\Actions\Geography\CommitRegionImport;
use App\Enums\ImportStatus;
use App\Models\Election;
use App\Models\GeographyImport;
use App\Models\Region;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expectedSha256 = '2f235ea43bcb9d81ce77793ee3dd772bf1cb3d3889d63525e2c1478d0fa01575';
$import = GeographyImport::query()->where('sha256', $expectedSha256)->latest('id')->firstOrFail();
$actualSha256 = hash_file('sha256', Storage::disk('local')->path($import->storage_path));

if (! hash_equals($expectedSha256, $actualSha256)) {
    throw new RuntimeException('SHA-256 batch geography tidak cocok. Commit dibatalkan.');
}

if (($import->summary['rows'] ?? null) !== 1145) {
    throw new RuntimeException('Jumlah baris preview berubah. Commit dibatalkan.');
}

if ($import->status === ImportStatus::Previewed) {
    $import = $app->make(CommitRegionImport::class)->handle($import);
}

$officialCounts = Region::query()
    ->where('source', 'BPS')
    ->where('source_version', $import->source_version)
    ->selectRaw('level, count(*) as aggregate')
    ->groupBy('level')
    ->pluck('aggregate', 'level')
    ->map(fn ($count) => (int) $count)
    ->all();

$eventSnapshots = Election::query()->withCount('regionSnapshots')->orderBy('id')->get()
    ->map(fn (Election $election) => [
        'id' => $election->id,
        'name' => $election->name,
        'status' => $election->status->value,
        'snapshot_regions' => $election->region_snapshots_count,
    ])->all();

echo json_encode([
    'import' => [
        'id' => $import->id,
        'status' => $import->status->value,
        'committed_at' => $import->committed_at?->toIso8601String(),
        'sha256' => $import->sha256,
    ],
    'official_counts' => $officialCounts,
    'demo_regions' => Region::query()->where('source', 'demo')->count(),
    'total_regions' => Region::query()->count(),
    'event_snapshots' => $eventSnapshots,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
