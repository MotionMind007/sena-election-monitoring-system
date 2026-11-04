<?php

use App\Http\Controllers\Admin\AssignmentController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ContestController;
use App\Http\Controllers\Admin\ContestParticipantController;
use App\Http\Controllers\Admin\ElectionController;
use App\Http\Controllers\Admin\ElectionPartyController;
use App\Http\Controllers\Admin\ElectoralDistrictController;
use App\Http\Controllers\Admin\GeographyController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\PartyController;
use App\Http\Controllers\Admin\PollingStationController;
use App\Http\Controllers\Admin\RegionImportController;
use App\Http\Controllers\Admin\ResultExportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OcrController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\RecapComparisonController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});
Route::get('/ready', ReadinessController::class)->middleware('throttle:health')->name('ready');
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/verification', [VerificationController::class, 'index'])->name('verification.index');
    Route::post('/verification/{submission}/review', [VerificationController::class, 'review'])->middleware('throttle:decision')->name('verification.review');
    Route::post('/verification/{submission}/decision', [VerificationController::class, 'decide'])->middleware('throttle:decision')->name('verification.decision');
});

Route::middleware(['auth', 'verified', 'role:super_admin,coordinator'])->group(function () {
    Route::get('/recap-comparisons/preview', [RecapComparisonController::class, 'preview'])->middleware('throttle:api')->name('recap-comparisons.preview');
    Route::get('/recap-comparisons/{comparison}/evidence', [RecapComparisonController::class, 'evidence'])->name('recap-comparisons.evidence');
});
Route::post('/recap-comparisons', [RecapComparisonController::class, 'store'])
    ->middleware(['auth', 'verified', 'role:super_admin', 'throttle:report-write'])
    ->name('recap-comparisons.store');

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');
Route::get('/peta-wilayah', DashboardController::class)->middleware(['auth', 'verified', 'role:super_admin,coordinator'])->name('map.index');
Route::get('/monitoring-tps', DashboardController::class)->middleware(['auth', 'verified', 'role:super_admin,coordinator'])->name('monitoring.index');
Route::get('/contest-participants/{participant}/photo', [ContestParticipantController::class, 'photo'])->middleware(['auth', 'verified'])->name('participants.photo');
Route::get('/parties/{party}/logo', [PartyController::class, 'logo'])->middleware(['auth', 'verified'])->name('parties.logo');
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/create', [ReportController::class, 'create'])->name('reports.create');
    Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:report-write')->name('reports.store');
    Route::get('/reports/{voteSubmission}', [ReportController::class, 'show'])->name('reports.show');
    Route::get('/report-evidence/{evidence}', [ReportController::class, 'evidence'])->name('reports.evidence');
    Route::post('/report-evidence/{evidence}/ocr', [OcrController::class, 'store'])->middleware('throttle:ocr')->name('reports.ocr.store');
});

Route::get('/admin', DashboardController::class)->middleware(['auth', 'verified', 'role:super_admin'])->name('admin.index');

Route::middleware(['auth', 'verified', 'role:super_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/events', [ElectionController::class, 'index'])->name('events.index');
    Route::post('/events', [ElectionController::class, 'store'])->middleware('throttle:admin-write')->name('events.store');
    Route::post('/parties', [PartyController::class, 'store'])->middleware('throttle:admin-write')->name('parties.store');
    Route::post('/events/{election}/dapil', [ElectoralDistrictController::class, 'store'])->middleware('throttle:admin-write')->name('electoral-districts.store');
    Route::post('/events/{election}/parties', [ElectionPartyController::class, 'store'])->middleware('throttle:admin-write')->name('election-parties.store');
    Route::patch('/events/{election}/status', [ElectionController::class, 'updateStatus'])->middleware('throttle:admin-write')->name('events.status');
    Route::delete('/events/{election}', [ElectionController::class, 'destroy'])->middleware('throttle:admin-write')->name('events.destroy');
    Route::post('/events/{election}/contests', [ContestController::class, 'store'])->middleware('throttle:admin-write')->name('contests.store');
    Route::patch('/contests/{contest}', [ContestController::class, 'update'])->middleware('throttle:admin-write')->name('contests.update');
    Route::post('/contests/{contest}/participants', [ContestParticipantController::class, 'store'])->middleware('throttle:admin-write')->name('participants.store');
    Route::post('/participants/{participant}', [ContestParticipantController::class, 'update'])->middleware('throttle:admin-write')->name('participants.update');
    Route::delete('/participants/{participant}', [ContestParticipantController::class, 'destroy'])->middleware('throttle:admin-write')->name('participants.destroy');
    Route::get('/geography', [GeographyController::class, 'index'])->name('geography.index');
    Route::post('/geography', [GeographyController::class, 'store'])->middleware('throttle:admin-write')->name('geography.store');
    Route::patch('/geography/{region}', [GeographyController::class, 'update'])->middleware('throttle:admin-write')->name('geography.update');
    Route::delete('/geography/{region}', [GeographyController::class, 'destroy'])->middleware('throttle:admin-write')->name('geography.destroy');
    Route::post('/geography/imports/preview', [RegionImportController::class, 'preview'])->middleware('throttle:admin-write')->name('geography-imports.preview');
    Route::post('/geography/imports/{geographyImport}/commit', [RegionImportController::class, 'commit'])->middleware('throttle:admin-write')->name('geography-imports.commit');
    Route::post('/polling-stations', [PollingStationController::class, 'store'])->middleware('throttle:admin-write')->name('polling-stations.store');
    Route::post('/polling-stations/provision', [PollingStationController::class, 'provision'])->middleware('throttle:admin-write')->name('polling-stations.provision');
    Route::get('/assignments', [AssignmentController::class, 'index'])->name('assignments.index');
    Route::post('/assignments', [AssignmentController::class, 'store'])->middleware('throttle:admin-write')->name('assignments.store');
    Route::post('/users', [AssignmentController::class, 'storeUser'])->middleware('throttle:admin-write')->name('users.store');
    Route::get('/operations', OperationsController::class)->name('operations.index');
    Route::get('/exports/results.csv', ResultExportController::class)->middleware('throttle:export')->name('exports.results');
    Route::get('/audit-logs', AuditLogController::class)->name('audit.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
