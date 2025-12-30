<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BulkVerificationJob;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

class EmailVerificationController extends Controller
{
    public function __construct(
        private EmailVerificationService $verificationService
    ) {
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'async' => 'sometimes|boolean',
        ]);

        $email = $request->input('email');
        $async = $request->boolean('async', false);
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        
        $team = $user->currentTeam;
        $userId = $user->id;
        $teamId = $team?->id;
        $token = $request->user()->currentAccessToken();
        // Get token ID only if it's a PersonalAccessToken (not TransientToken from session)
        $tokenId = ($token && $token instanceof \Laravel\Sanctum\PersonalAccessToken) ? $token->id : null;

        if ($async) {
            // Dispatch to queue
            \App\Jobs\VerifyEmailJob::dispatch($email, $userId, $teamId, $tokenId);
            
            return response()->json([
                'message' => 'Verification queued',
                'email' => $email,
            ], 202);
        }

        // Synchronous verification
        $result = $this->verificationService->verify($email, $userId, $teamId, $tokenId);

        return response()->json($result);
    }

    public function batch(Request $request): JsonResponse
    {
        $request->validate([
            'emails' => 'required|array|max:100',
            'emails.*' => 'required|email',
            'async' => 'sometimes|boolean',
        ]);

        $emails = $request->input('emails');
        $async = $request->boolean('async', false);
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        
        $team = $user->currentTeam;
        $userId = $user->id;
        $teamId = $team?->id;
        $token = $request->user()->currentAccessToken();
        // Get token ID only if it's a PersonalAccessToken (not TransientToken from session)
        $tokenId = ($token && $token instanceof \Laravel\Sanctum\PersonalAccessToken) ? $token->id : null;

        // Create a BulkVerificationJob for UI batch verification
        $bulkJob = BulkVerificationJob::create([
            'user_id' => $userId,
            'team_id' => $teamId,
            'api_key_id' => $tokenId,
            'filename' => 'Batch Verification - ' . now()->format('Y-m-d H:i:s'),
            'file_path' => null, // No file for UI batch
            'total_emails' => count($emails),
            'status' => 'processing',
            'started_at' => now(),
        ]);

        if ($async) {
            foreach ($emails as $email) {
                \App\Jobs\VerifyEmailJob::dispatch($email, $userId, $teamId, $tokenId, $bulkJob->id);
            }

            // Dispatch completion check job
            \App\Jobs\CheckBulkVerificationCompletionJob::dispatch($bulkJob->id)
                ->delay(now()->addSeconds(10));

            return response()->json([
                'message' => 'Verifications queued',
                'count' => count($emails),
                'bulk_job_id' => $bulkJob->id,
            ], 202);
        }

        // Synchronous verification
        $results = [];
        $validCount = 0;
        $invalidCount = 0;
        $riskyCount = 0;

        foreach ($emails as $email) {
            $result = $this->verificationService->verify($email, $userId, $teamId, $tokenId, $bulkJob->id);
            $results[] = $result;

            // Update counts
            if ($result['status'] === 'valid') {
                $validCount++;
            } elseif ($result['status'] === 'invalid') {
                $invalidCount++;
            } elseif (in_array($result['status'], ['catch_all', 'risky', 'do_not_mail'])) {
                $riskyCount++;
            }
        }

        // Update bulk job status
        $bulkJob->update([
            'status' => 'completed',
            'processed_emails' => count($results),
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'risky_count' => $riskyCount,
            'completed_at' => now(),
        ]);

        return response()->json([
            'results' => $results,
            'count' => count($results),
            'bulk_job_id' => $bulkJob->id,
        ]);
    }
}
