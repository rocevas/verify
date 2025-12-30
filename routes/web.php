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

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');
    
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
    
    // Dashboard API routes
    Route::prefix('api/dashboard')->group(function () {
        Route::get('/stats', [\App\Http\Controllers\DashboardController::class, 'stats']);
        Route::get('/recent', [\App\Http\Controllers\DashboardController::class, 'recent']);
        Route::get('/bulk-jobs/{bulkJob}/emails', [\App\Http\Controllers\DashboardController::class, 'bulkJobEmails']);
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
    
    // Bulk verification routes (for UI)
    Route::prefix('api/bulk')->group(function () {
        Route::post('/upload', [\App\Http\Controllers\Api\BulkVerificationController::class, 'upload']);
        Route::get('/jobs', [\App\Http\Controllers\Api\BulkVerificationController::class, 'list']);
        Route::get('/jobs/{id}', [\App\Http\Controllers\Api\BulkVerificationController::class, 'status']);
        Route::get('/jobs/{id}/download', [\App\Http\Controllers\Api\BulkVerificationController::class, 'download']);
    });
});
