<?php

declare(strict_types=1);

use App\Actions\Geography\ParseRegionCsv;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$path = storage_path('app/private/geography-imports/source/papua-bps-2025-semester-2.csv');
$result = $app->make(ParseRegionCsv::class)->handle($path);

echo json_encode($result['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
