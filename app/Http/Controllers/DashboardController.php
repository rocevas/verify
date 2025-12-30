<?php

namespace App\Http\Controllers;

use App\Models\BulkVerificationJob;
use App\Models\EmailVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json([
                'today' => ['total' => 0, 'valid' => 0, 'invalid' => 0, 'risky' => 0, 'percentages' => ['valid' => 0, 'invalid' => 0, 'risky' => 0]],
                'month' => ['total' => 0, 'valid' => 0, 'invalid' => 0, 'risky' => 0, 'percentages' => ['valid' => 0, 'invalid' => 0, 'risky' => 0]],
            ]);
        }

        // Today's stats - filter by team_id
        $todayStats = EmailVerification::where('team_id', $teamId)
            ->whereDate('created_at', today())
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as invalid,
                SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as risky
            ', ['valid', 'invalid', 'catch_all', 'risky', 'do_not_mail'])
            ->first();

        // Month's stats - filter by team_id
        $monthStats = EmailVerification::where('team_id', $teamId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as invalid,
                SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as risky
            ', ['valid', 'invalid', 'catch_all', 'risky', 'do_not_mail'])
            ->first();

        // Calculate percentages
        $todayPercentages = [
            'valid' => $todayStats->total > 0 ? round(($todayStats->valid / $todayStats->total) * 100, 2) : 0,
            'invalid' => $todayStats->total > 0 ? round(($todayStats->invalid / $todayStats->total) * 100, 2) : 0,
            'risky' => $todayStats->total > 0 ? round(($todayStats->risky / $todayStats->total) * 100, 2) : 0,
        ];

        $monthPercentages = [
            'valid' => $monthStats->total > 0 ? round(($monthStats->valid / $monthStats->total) * 100, 2) : 0,
            'invalid' => $monthStats->total > 0 ? round(($monthStats->invalid / $monthStats->total) * 100, 2) : 0,
            'risky' => $monthStats->total > 0 ? round(($monthStats->risky / $monthStats->total) * 100, 2) : 0,
        ];

        return response()->json([
            'today' => [
                'total' => $todayStats->total ?? 0,
                'valid' => $todayStats->valid ?? 0,
                'invalid' => $todayStats->invalid ?? 0,
                'risky' => $todayStats->risky ?? 0,
                'percentages' => $todayPercentages,
            ],
            'month' => [
                'total' => $monthStats->total ?? 0,
                'valid' => $monthStats->valid ?? 0,
                'invalid' => $monthStats->invalid ?? 0,
                'risky' => $monthStats->risky ?? 0,
                'percentages' => $monthPercentages,
            ],
        ]);
    }

    public function recent(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json([
                'bulk_jobs' => [],
                'individual_verifications' => [],
                'all_verifications' => [],
            ]);
        }

        // Get bulk jobs with their stats - filter by team_id
        // Optimize: Get all bulk job IDs first, then get stats in one query
        $bulkJobs = BulkVerificationJob::where('team_id', $teamId)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        // Get all stats in one query to avoid N+1 problem
        $bulkJobIds = $bulkJobs->pluck('id');
        $statsQuery = EmailVerification::whereIn('bulk_verification_job_id', $bulkJobIds)
            ->selectRaw('
                bulk_verification_job_id,
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as invalid,
                SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as risky
            ', ['valid', 'invalid', 'catch_all', 'risky', 'do_not_mail'])
            ->groupBy('bulk_verification_job_id')
            ->get()
            ->keyBy('bulk_verification_job_id');

        $bulkJobs = $bulkJobs->map(function ($bulkJob) use ($statsQuery) {
            $stats = $statsQuery->get($bulkJob->id);

            return [
                'id' => $bulkJob->id,
                'type' => 'bulk',
                'filename' => $bulkJob->filename,
                'status' => $bulkJob->status,
                'total_emails' => $bulkJob->total_emails,
                'processed_emails' => $bulkJob->processed_emails ?? 0,
                'progress_percentage' => $bulkJob->progress_percentage,
                'stats' => [
                    'valid' => $stats->valid ?? 0,
                    'invalid' => $stats->invalid ?? 0,
                    'risky' => $stats->risky ?? 0,
                    'total' => $stats->total ?? 0,
                ],
                'created_at' => $bulkJob->created_at?->toIso8601String() ?? $bulkJob->created_at,
                'completed_at' => $bulkJob->completed_at?->toIso8601String() ?? $bulkJob->completed_at,
            ];
        });

        // Get individual verifications (not part of bulk jobs) - filter by team_id
        $individualVerifications = EmailVerification::where('team_id', $teamId)
            ->whereNull('bulk_verification_job_id')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($verification) {
                return [
                    'id' => $verification->id,
                    'type' => 'individual',
                    'email' => $verification->email,
                    'status' => $verification->status,
                    'score' => $verification->score,
                    'checks' => $verification->checks,
                    'created_at' => $verification->created_at?->toIso8601String() ?? $verification->created_at,
                ];
            });

        return response()->json([
            'bulk_jobs' => $bulkJobs,
            'individual_verifications' => $individualVerifications,
        ]);
    }

    public function bulkJobEmails(int $bulkJobId, Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json(['error' => 'No team selected'], 403);
        }

        // Verify the bulk job belongs to the team
        $bulkJob = BulkVerificationJob::where('id', $bulkJobId)
            ->where('team_id', $teamId)
            ->firstOrFail();

        // Get all emails for this bulk job
        $emails = EmailVerification::where('bulk_verification_job_id', $bulkJobId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($verification) {
                return [
                    'id' => $verification->id,
                    'email' => $verification->email,
                    'status' => $verification->status,
                    'score' => $verification->score,
                    'checks' => $verification->checks,
                    'created_at' => $verification->created_at,
                ];
            });

        return response()->json($emails);
    }
}
