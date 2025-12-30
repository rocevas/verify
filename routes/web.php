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
    
    // Dashboard API routes
    Route::prefix('api/dashboard')->group(function () {
        Route::get('/stats', [\App\Http\Controllers\DashboardController::class, 'stats']);
        Route::get('/recent', [\App\Http\Controllers\DashboardController::class, 'recent']);
        Route::get('/bulk-jobs/{bulkJob}/emails', [\App\Http\Controllers\DashboardController::class, 'bulkJobEmails']);
    });
    
    // Email verification routes (for UI)
    Route::prefix('api/verify')->group(function () {
        Route::post('/', [\App\Http\Controllers\Api\EmailVerificationController::class, 'verify']);
        Route::post('/batch', [\App\Http\Controllers\Api\EmailVerificationController::class, 'batch']);
    });
});
