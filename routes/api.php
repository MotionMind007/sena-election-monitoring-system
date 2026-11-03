<?php

use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\PollingStationController;
use App\Http\Controllers\Api\V1\RegionController;
use App\Http\Controllers\Api\V1\VoteSubmissionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('v1')->group(function () {
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{election}/regions/{region}/children', [RegionController::class, 'children']);
    Route::get('/events/{election}/tps', [PollingStationController::class, 'index']);
    Route::post('/vote-submissions', [VoteSubmissionController::class, 'store']);
});
