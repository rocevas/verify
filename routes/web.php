<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// DMARC report webhook (public endpoint for receiving reports)
Route::post('/webhooks/dmarc-report', [\App\Http\Controllers\DmarcReportController::class, 'receive'])
    ->name('webhooks.dmarc-report');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    // New AI Dashboard (ChatGPT-like)
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');
    
    Route::get('/dashboard-old', function () {
        return Inertia::render('DashboardOld');
    })->name('dashboard-old');

    // Email Verifications page
    Route::get('/verifications', function () {
        return Inertia::render('EmailVerifications');
    })->name('verifications');

    // Import Verifications page
    Route::get('/verifications/import', function () {
        return Inertia::render('ImportVerifications');
    })->name('verifications.import');

    // Bulk Verification Detail page
    Route::get('/verifications/bulk/{id}', function (int $id) {
        return Inertia::render('BulkVerificationDetail', ['bulkJobId' => $id]);
    })->name('verifications.bulk');

    // Individual Email Verification Detail page
    Route::get('/verifications/email/{id}', function (int $id) {
        return Inertia::render('EmailVerificationDetail', ['verificationId' => $id]);
    })->name('verifications.email');

    // Monitors page
    Route::get('/monitors', [\App\Http\Controllers\MonitorController::class, 'index'])->name('monitors');

    // Blocklist Monitor Detail page
    Route::get('/monitors/blocklist/{id}', function (int $id) {
        return Inertia::render('BlocklistMonitorDetail', ['monitorId' => $id]);
    })->name('monitors.blocklist.detail');

    // DMARC Monitor Detail page
    Route::get('/monitors/dmarc/{id}', function (int $id) {
        return Inertia::render('DmarcMonitorDetail', ['monitorId' => $id]);
    })->name('monitors.dmarc.detail');

    // Inbox Insight page
    Route::get('/inbox-insight', [\App\Http\Controllers\EmailCampaignController::class, 'index'])->name('inbox-insight');

    // Email Campaign Check page
    Route::get('/inbox-insight/campaign/{id}/check', [\App\Http\Controllers\EmailCampaignController::class, 'check'])->name('inbox-insight.check');

    // Dashboard API routes
    Route::prefix('api/dashboard')->group(function () {
        Route::get('/stats', [\App\Http\Controllers\DashboardController::class, 'stats']);
        Route::get('/chart', [\App\Http\Controllers\DashboardController::class, 'chart']);
        Route::get('/recent', [\App\Http\Controllers\DashboardController::class, 'recent']);
        Route::get('/bulk-jobs/{bulkJob}/emails', [\App\Http\Controllers\DashboardController::class, 'bulkJobEmails']);
        Route::get('/verifications/{verification}', [\App\Http\Controllers\DashboardController::class, 'verificationDetail']);
    });

    // Bulk Verification Detail API routes
    Route::prefix('api/bulk-jobs')->group(function () {
        Route::get('/{bulkJob}', [\App\Http\Controllers\DashboardController::class, 'bulkJobDetail']);
        Route::get('/{bulkJob}/emails', [\App\Http\Controllers\DashboardController::class, 'bulkJobEmailsPaginated']);
    });

    // Email Verifications API routes
    Route::prefix('api/verifications')->group(function () {
        Route::get('/stats', [\App\Http\Controllers\EmailVerificationController::class, 'stats']);
        Route::get('/chart', [\App\Http\Controllers\EmailVerificationController::class, 'chart']);
        Route::get('/history', [\App\Http\Controllers\EmailVerificationController::class, 'history']);
        Route::get('/lists', [\App\Http\Controllers\EmailVerificationController::class, 'lists']);
    });

    // Email verification routes (for UI)
    Route::prefix('api/verify')->group(function () {
        Route::post('/', [\App\Http\Controllers\Api\EmailVerificationController::class, 'verify']);
        Route::post('/batch', [\App\Http\Controllers\Api\EmailVerificationController::class, 'batch']);
    });

    // AI Email verification routes (streaming)
    Route::prefix('api/ai/verify')->group(function () {
        Route::post('/stream', [\App\Http\Controllers\Api\AiVerificationController::class, 'verifyStream']);
        Route::post('/batch/stream', [\App\Http\Controllers\Api\AiVerificationController::class, 'batchStream']);
        Route::post('/upload/stream', [\App\Http\Controllers\Api\AiVerificationController::class, 'uploadStream']);
    });

    // Bulk verification routes (for UI)
    Route::prefix('api/bulk')->group(function () {
        Route::post('/upload', [\App\Http\Controllers\Api\BulkVerificationController::class, 'upload']);
        Route::get('/jobs', [\App\Http\Controllers\Api\BulkVerificationController::class, 'list']);
        Route::get('/jobs/{id}', [\App\Http\Controllers\Api\BulkVerificationController::class, 'status']);
        Route::get('/jobs/{id}/download', [\App\Http\Controllers\Api\BulkVerificationController::class, 'download']);
    });

    // Monitor routes (for UI)
    Route::prefix('api/monitors')->group(function () {
        // Blocklist monitors
        Route::get('/blocklist', [\App\Http\Controllers\Api\MonitorController::class, 'blocklistIndex']);
        Route::post('/blocklist', [\App\Http\Controllers\Api\MonitorController::class, 'blocklistStore']);
        Route::put('/blocklist/{id}', [\App\Http\Controllers\Api\MonitorController::class, 'blocklistUpdate']);
        Route::delete('/blocklist/{id}', [\App\Http\Controllers\Api\MonitorController::class, 'blocklistDestroy']);
        Route::post('/blocklist/{id}/check', [\App\Http\Controllers\Api\MonitorController::class, 'blocklistCheckNow']);

        // DMARC monitors
        Route::get('/dmarc', [\App\Http\Controllers\Api\MonitorController::class, 'dmarcIndex']);
        Route::post('/dmarc', [\App\Http\Controllers\Api\MonitorController::class, 'dmarcStore']);
        Route::put('/dmarc/{id}', [\App\Http\Controllers\Api\MonitorController::class, 'dmarcUpdate']);
        Route::delete('/dmarc/{id}', [\App\Http\Controllers\Api\MonitorController::class, 'dmarcDestroy']);
        Route::post('/dmarc/{id}/check', [\App\Http\Controllers\Api\MonitorController::class, 'dmarcCheckNow']);

        // Check results
        Route::get('/{type}/{id}/results', [\App\Http\Controllers\Api\MonitorController::class, 'checkResults']);

        // DMARC monitor detail
        Route::get('/dmarc/{id}/detail', [\App\Http\Controllers\Api\MonitorController::class, 'dmarcDetail']);
    });

    // Blacklist routes
    Route::prefix('api/blacklists')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\BlacklistController::class, 'index']);
    });

    // Email Campaign routes (for UI)
    Route::prefix('api/campaigns')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\EmailCampaignController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\EmailCampaignController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\EmailCampaignController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\EmailCampaignController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\EmailCampaignController::class, 'destroy']);
        Route::post('/{id}/check', [\App\Http\Controllers\Api\EmailCampaignController::class, 'check']);
        Route::get('/{id}/results', [\App\Http\Controllers\Api\EmailCampaignController::class, 'checkResults']);
    });
});
