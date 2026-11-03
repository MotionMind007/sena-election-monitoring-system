<?php

declare(strict_types=1);

use App\Actions\Geography\ParseRegionCsv;
use App\Enums\ImportStatus;
use App\Enums\UserRole;
use App\Models\GeographyImport;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$path = storage_path('app/private/geography-imports/source/papua-bps-2025-semester-2.csv');
$sha256 = hash_file('sha256', $path);
$existing = GeographyImport::query()
    ->where('sha256', $sha256)
    ->where('status', ImportStatus::Previewed)
    ->latest('id')
    ->first();

if ($existing !== null) {
    echo json_encode(['id' => $existing->id, 'status' => 'already_previewed', 'summary' => $existing->summary], JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}

$parsed = $app->make(ParseRegionCsv::class)->handle($path);
$actor = User::query()->where('role', UserRole::SuperAdmin)->oldest('id')->firstOrFail();
$storagePath = 'geography-imports/'.Str::uuid().'.csv';

if (! Storage::disk('local')->put($storagePath, file_get_contents($path))) {
    throw new RuntimeException('Unable to stage the geography CSV.');
}

$import = GeographyImport::query()->create([
    'actor_id' => $actor->id,
    'source' => 'BPS',
    'source_version' => 'SIG 2025 Semester 2 (BPS) - 2025 (Kemendagri)',
    'original_name' => basename($path),
    'storage_path' => $storagePath,
    'sha256' => $sha256,
    'status' => ImportStatus::Previewed,
    'summary' => $parsed['summary'],
]);

echo json_encode(['id' => $import->id, 'status' => $import->status->value, 'summary' => $import->summary], JSON_PRETTY_PRINT).PHP_EOL;
