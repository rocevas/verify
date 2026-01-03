<?php

use App\Http\Controllers\Api\BulkVerificationController;
use App\Http\Controllers\Api\EmailVerificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Sanctum authenticated routes (using Jetstream API tokens)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    
    // Email verification
    Route::prefix('verify')->group(function () {
        Route::post('/', [EmailVerificationController::class, 'verify']);
        Route::post('/batch', [EmailVerificationController::class, 'batch']);
    });

    // Bulk verification
    Route::prefix('bulk')->group(function () {
        Route::post('/upload', [BulkVerificationController::class, 'upload']);
        Route::get('/jobs', [BulkVerificationController::class, 'list']);
        Route::get('/jobs/{bulkJob:uuid}', [BulkVerificationController::class, 'status']);
        Route::get('/jobs/{bulkJob:uuid}/download', [BulkVerificationController::class, 'download']);
    });
});
