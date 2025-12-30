<?php

namespace App\Http\Controllers;

use App\Models\BulkVerificationJob;
use App\Models\EmailVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailVerificationController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json([
                'total' => 0,
                'valid' => 0,
                'invalid' => 0,
                'risky' => 0,
                'percentages' => ['valid' => 0, 'invalid' => 0, 'risky' => 0],
            ]);
        }

        // Overall stats - filter by team_id
        $stats = EmailVerification::where('team_id', $teamId)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as invalid,
                SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as risky
            ', ['valid', 'invalid', 'catch_all', 'risky', 'do_not_mail'])
            ->first();

        $percentages = [
            'valid' => $stats->total > 0 ? round(($stats->valid / $stats->total) * 100, 2) : 0,
            'invalid' => $stats->total > 0 ? round(($stats->invalid / $stats->total) * 100, 2) : 0,
            'risky' => $stats->total > 0 ? round(($stats->risky / $stats->total) * 100, 2) : 0,
        ];

        return response()->json([
            'total' => $stats->total ?? 0,
            'valid' => $stats->valid ?? 0,
            'invalid' => $stats->invalid ?? 0,
            'risky' => $stats->risky ?? 0,
            'percentages' => $percentages,
        ]);
    }

    public function chart(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json(['data' => []]);
        }

        // Get last 30 days of data grouped by date
        $chartData = EmailVerification::where('team_id', $teamId)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as invalid,
                SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as risky
            ', ['valid', 'invalid', 'catch_all', 'risky', 'do_not_mail'])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'total' => (int) $item->total,
                    'valid' => (int) $item->valid,
                    'invalid' => (int) $item->invalid,
                    'risky' => (int) $item->risky,
                ];
            });

        return response()->json(['data' => $chartData]);
    }

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json(['data' => [], 'pagination' => []]);
        }

        $perPage = $request->get('per_page', 50);
        $page = $request->get('page', 1);

        $verifications = EmailVerification::where('team_id', $teamId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = $verifications->map(function ($verification) {
            return [
                'id' => $verification->id,
                'email' => $verification->email,
                'status' => $verification->status,
                'score' => $verification->score,
                'checks' => $verification->checks,
                'source' => $verification->source,
                'error' => $verification->error,
                'created_at' => $verification->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $data,
            'pagination' => [
                'current_page' => $verifications->currentPage(),
                'last_page' => $verifications->lastPage(),
                'per_page' => $verifications->perPage(),
                'total' => $verifications->total(),
            ],
        ]);
    }

    public function lists(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json(['data' => [], 'pagination' => []]);
        }

        $perPage = $request->get('per_page', 20);
        $page = $request->get('page', 1);

        $bulkJobs = BulkVerificationJob::where('team_id', $teamId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

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

        $data = $bulkJobs->map(function ($bulkJob) use ($statsQuery) {
            $stats = $statsQuery->get($bulkJob->id);

            return [
                'id' => $bulkJob->id,
                'filename' => $bulkJob->filename,
                'source' => $bulkJob->source,
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
                'created_at' => $bulkJob->created_at?->toIso8601String(),
                'completed_at' => $bulkJob->completed_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $data,
            'pagination' => [
                'current_page' => $bulkJobs->currentPage(),
                'last_page' => $bulkJobs->lastPage(),
                'per_page' => $bulkJobs->perPage(),
                'total' => $bulkJobs->total(),
            ],
        ]);
    }
}
