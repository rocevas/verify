<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BulkVerificationJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BulkVerificationController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240', // 10MB max
        ]);

        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;
        $token = $user->currentAccessToken();
        // Get token ID only if it's a PersonalAccessToken (not TransientToken from session)
        $tokenId = ($token instanceof \Laravel\Sanctum\PersonalAccessToken) ? $token->id : null;

        $file = $request->file('file');
        $filename = $file->getClientOriginalName();
        $path = $file->store('bulk-uploads', 'local');

        // Count emails in file
        $totalEmails = 0;
        $fullPath = Storage::disk('local')->path($path);
        $handle = fopen($fullPath, 'r');
        if ($handle) {
            while (($line = fgetcsv($handle)) !== false) {
                if (!empty($line[0]) && filter_var($line[0], FILTER_VALIDATE_EMAIL)) {
                    $totalEmails++;
                }
            }
            fclose($handle);
        }

        // Determine source: 'csv' if using PersonalAccessToken, 'ui' if using session (TransientToken)
        $source = ($token && $token instanceof \Laravel\Sanctum\PersonalAccessToken) ? 'csv' : 'ui';

        $job = BulkVerificationJob::create([
            'user_id' => $user->id,
            'team_id' => $teamId,
            'api_key_id' => $tokenId, // Store token ID for reference (null for session-based auth)
            'source' => $source,
            'filename' => $filename,
            'file_path' => $path,
            'total_emails' => $totalEmails,
            'status' => 'pending',
        ]);

        // Dispatch job to process
        \App\Jobs\ProcessBulkVerificationJob::dispatch($job);

        return response()->json([
            'message' => 'File uploaded and processing started',
            'job_id' => $job->id,
            'total_emails' => $totalEmails,
        ], 202);
    }

    public function status(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json(['error' => 'No team selected'], 403);
        }

        $job = BulkVerificationJob::where('id', $id)
            ->where('team_id', $teamId)
            ->firstOrFail();

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'total_emails' => $job->total_emails,
            'processed_emails' => $job->processed_emails,
            'progress_percentage' => round($job->progress_percentage, 2),
            'valid_count' => $job->valid_count,
            'invalid_count' => $job->invalid_count,
            'risky_count' => $job->risky_count,
            'created_at' => $job->created_at,
            'completed_at' => $job->completed_at,
        ]);
    }

    public function download(int $id, Request $request)
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json(['error' => 'No team selected'], 403);
        }

        $job = BulkVerificationJob::where('id', $id)
            ->where('team_id', $teamId)
            ->firstOrFail();

        if ($job->status !== 'completed' || !$job->result_file_path) {
            return response()->json(['error' => 'Job not completed or result not available'], 404);
        }

        return Storage::disk('local')->download($job->result_file_path, "verification_results_{$job->id}.csv");
    }

    public function list(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json(['data' => []]);
        }

        $jobs = BulkVerificationJob::where('team_id', $teamId)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($jobs);
    }
}
