<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiEmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiVerificationController extends Controller
{
    public function __construct(
        private AiEmailVerificationService $aiService
    ) {
    }

    /**
     * Verify single email with streaming response
     */
    public function verifyStream(Request $request): StreamedResponse
    {
        try {
            $request->validate([
                'email' => 'required|email',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return new StreamedResponse(function () use ($e) {
                echo "data: " . json_encode([
                    'type' => 'error',
                    'message' => 'Validation failed: ' . $e->getMessage(),
                ]) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }, 422, [
                'Content-Type' => 'text/event-stream',
            ]);
        }

        $email = $request->input('email');
        $user = $request->user();

        if (!$user) {
            return new StreamedResponse(function () {
                echo "data: " . json_encode([
                    'type' => 'error',
                    'message' => 'Unauthenticated',
                ]) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }, 401, [
                'Content-Type' => 'text/event-stream',
            ]);
        }

        $team = $user->currentTeam;
        $userId = $user->id;
        $teamId = $team?->id;
        $token = $request->user()->currentAccessToken();
        $tokenId = ($token && $token instanceof \Laravel\Sanctum\PersonalAccessToken) ? $token->id : null;
        $source = ($token && $token instanceof \Laravel\Sanctum\PersonalAccessToken) ? 'api' : 'ui';

        return new StreamedResponse(function () use ($email, $userId, $teamId, $tokenId, $source) {
            try {
                $this->aiService->verifyWithAi(
                    $email,
                    $userId,
                    $teamId,
                    $tokenId,
                    null,
                    $source,
                    function ($data) {
                        echo "data: " . json_encode($data) . "\n\n";
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                );
            } catch (\Exception $e) {
                Log::error('AI verification error', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                echo "data: " . json_encode([
                    'type' => 'error',
                    'message' => 'Verification failed: ' . $e->getMessage(),
                ]) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Verify batch emails with streaming response
     */
    public function batchStream(Request $request): StreamedResponse
    {
        $request->validate([
            'emails' => 'required|array|max:100',
            'emails.*' => 'required|email',
        ]);

        $emails = $request->input('emails');
        $user = $request->user();

        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        $team = $user->currentTeam;
        $userId = $user->id;
        $teamId = $team?->id;

        if (!$teamId) {
            abort(403, 'No team selected');
        }

        $token = $request->user()->currentAccessToken();
        $tokenId = ($token && $token instanceof \Laravel\Sanctum\PersonalAccessToken) ? $token->id : null;
        $source = ($token && $token instanceof \Laravel\Sanctum\PersonalAccessToken) ? 'api' : 'ui';

        // Create bulk job
        $bulkJob = \App\Models\BulkVerificationJob::create([
            'user_id' => $user->id,
            'team_id' => $teamId,
            'api_key_id' => $tokenId,
            'source' => $source,
            'filename' => 'AI Batch Verification - ' . now()->format('Y-m-d H:i:s'),
            'file_path' => null,
            'total_emails' => count($emails),
            'status' => 'processing',
            'started_at' => now(),
        ]);

        return new StreamedResponse(function () use ($emails, $userId, $teamId, $tokenId, $bulkJob, $source) {
            $results = [];
            $validCount = 0;
            $invalidCount = 0;
            $riskyCount = 0;
            
            $sendStream = function ($data) {
                echo "data: " . json_encode($data) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            foreach ($emails as $index => $email) {
                $sendStream([
                    'type' => 'email_start',
                    'email' => $email,
                    'index' => $index + 1,
                    'total' => count($emails),
                ]);

                $result = $this->aiService->verifyWithAi(
                    $email,
                    $userId,
                    $teamId,
                    $tokenId,
                    $bulkJob->id,
                    $source,
                    function ($data) use ($email, $sendStream) {
                        $data['email'] = $email;
                        $sendStream($data);
                    }
                );

                $results[] = $result;

                // Update counts
                if ($result['status'] === 'valid') {
                    $validCount++;
                } elseif ($result['status'] === 'invalid') {
                    $invalidCount++;
                } elseif (in_array($result['status'], ['catch_all', 'risky', 'do_not_mail'])) {
                    $riskyCount++;
                }

                $sendStream([
                    'type' => 'email_complete',
                    'email' => $email,
                    'result' => $result,
                    'progress' => [
                        'current' => $index + 1,
                        'total' => count($emails),
                        'valid' => $validCount,
                        'invalid' => $invalidCount,
                        'risky' => $riskyCount,
                    ],
                ]);
            }

            // Update bulk job
            $bulkJob->update([
                'status' => 'completed',
                'processed_emails' => count($results),
                'valid_count' => $validCount,
                'invalid_count' => $invalidCount,
                'risky_count' => $riskyCount,
                'completed_at' => now(),
            ]);

            $sendStream([
                'type' => 'batch_complete',
                'bulk_job_id' => $bulkJob->id,
                'summary' => [
                    'total' => count($results),
                    'valid' => $validCount,
                    'invalid' => $invalidCount,
                    'risky' => $riskyCount,
                ],
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Upload file and verify with streaming
     */
    public function uploadStream(Request $request): StreamedResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;
        $token = $user->currentAccessToken();
        $tokenId = ($token instanceof \Laravel\Sanctum\PersonalAccessToken) ? $token->id : null;
        $source = ($token && $token instanceof \Laravel\Sanctum\PersonalAccessToken) ? 'csv' : 'ui';

        $file = $request->file('file');
        $filename = $file->getClientOriginalName();
        $path = $file->store('bulk-uploads', 'local');

        // Parse emails from file
        $emails = [];
        $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($path);
        $handle = fopen($fullPath, 'r');
        if ($handle) {
            while (($line = fgetcsv($handle)) !== false) {
                if (!empty($line[0]) && filter_var($line[0], FILTER_VALIDATE_EMAIL)) {
                    $emails[] = trim($line[0]);
                }
            }
            fclose($handle);
        }

        if (empty($emails)) {
            abort(422, 'No valid emails found in file');
        }

        // Create bulk job
        $bulkJob = \App\Models\BulkVerificationJob::create([
            'user_id' => $user->id,
            'team_id' => $teamId,
            'api_key_id' => $tokenId,
            'source' => $source,
            'filename' => $filename,
            'file_path' => $path,
            'total_emails' => count($emails),
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $userId = $user->id;

        return new StreamedResponse(function () use ($emails, $userId, $teamId, $tokenId, $bulkJob, $source) {
            $results = [];
            $validCount = 0;
            $invalidCount = 0;
            $riskyCount = 0;
            
            $sendStream = function ($data) {
                echo "data: " . json_encode($data) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            foreach ($emails as $index => $email) {
                $sendStream([
                    'type' => 'email_start',
                    'email' => $email,
                    'index' => $index + 1,
                    'total' => count($emails),
                ]);

                $result = $this->aiService->verifyWithAi(
                    $email,
                    $userId,
                    $teamId,
                    $tokenId,
                    $bulkJob->id,
                    $source,
                    function ($data) use ($email, $sendStream) {
                        $data['email'] = $email;
                        $sendStream($data);
                    }
                );

                $results[] = $result;

                if ($result['status'] === 'valid') {
                    $validCount++;
                } elseif ($result['status'] === 'invalid') {
                    $invalidCount++;
                } elseif (in_array($result['status'], ['catch_all', 'risky', 'do_not_mail'])) {
                    $riskyCount++;
                }

                $sendStream([
                    'type' => 'email_complete',
                    'email' => $email,
                    'result' => $result,
                    'progress' => [
                        'current' => $index + 1,
                        'total' => count($emails),
                        'valid' => $validCount,
                        'invalid' => $invalidCount,
                        'risky' => $riskyCount,
                    ],
                ]);
            }

            $bulkJob->update([
                'status' => 'completed',
                'processed_emails' => count($results),
                'valid_count' => $validCount,
                'invalid_count' => $invalidCount,
                'risky_count' => $riskyCount,
                'completed_at' => now(),
            ]);

            $sendStream([
                'type' => 'batch_complete',
                'bulk_job_id' => $bulkJob->id,
                'summary' => [
                    'total' => count($results),
                    'valid' => $validCount,
                    'invalid' => $invalidCount,
                    'risky' => $riskyCount,
                ],
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function sendStream(array $data): void
    {
        echo "data: " . json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }
}

