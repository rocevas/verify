<?php

namespace App\Http\Controllers;

use App\Models\BulkVerificationJob;
use App\Models\EmailVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as invalid,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as risky
            ', ['deliverable', 'undeliverable', 'risky'])
            ->first();

        // Month's stats - filter by team_id
        $monthStats = EmailVerification::where('team_id', $teamId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as invalid,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as risky
            ', ['deliverable', 'undeliverable', 'risky'])
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
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as invalid,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as risky
            ', ['deliverable', 'undeliverable', 'risky'])
            ->groupBy(\DB::raw('DATE(created_at)'))
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
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as invalid,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as risky
            ', ['deliverable', 'undeliverable', 'risky'])
            ->groupBy('bulk_verification_job_id')
            ->get()
            ->keyBy('bulk_verification_job_id');

        $bulkJobs = $bulkJobs->map(function ($bulkJob) use ($statsQuery) {
            $stats = $statsQuery->get($bulkJob->id);

            return [
                'id' => $bulkJob->uuid,
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
                // Build checks array from individual columns
                $checks = [
                    'syntax' => $verification->syntax ?? false,
                    'mx_record' => $verification->mx_record ?? false,
                    'smtp' => $verification->smtp ?? false,
                    'disposable' => $verification->disposable ?? false,
                    'role' => $verification->role ?? false,
                    'no_reply' => $verification->no_reply ?? false,
                    'typo_domain' => $verification->typo_domain ?? false,
                    'mailbox_full' => $verification->mailbox_full ?? false,
                    'is_free' => $verification->is_free ?? false,
                    'blacklist' => $verification->blacklist ?? false,
                    'domain_validity' => $verification->domain_validity ?? false,
                    'isp_esp' => $verification->isp_esp ?? false,
                    'government_tld' => $verification->government_tld ?? false,
                    'ai_analysis' => $verification->ai_analysis ?? false,
                ];
                
                return [
                    'id' => $verification->uuid,
                    'type' => 'individual',
                    'email' => $verification->email,
                    'state' => $verification->state,
                    'result' => $verification->result,
                    'score' => $verification->score,
                    'checks' => $checks,
                    'created_at' => $verification->created_at?->toIso8601String() ?? $verification->created_at,
                ];
            });

        return response()->json([
            'bulk_jobs' => $bulkJobs,
            'individual_verifications' => $individualVerifications,
        ]);
    }

    public function bulkJobEmails(BulkVerificationJob $bulkJob, Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json(['error' => 'No team selected'], 403);
        }

        // Verify the bulk job belongs to the team
        $bulkJob = BulkVerificationJob::where('uuid', $bulkJob->uuid)
            ->where('team_id', $teamId)
            ->firstOrFail();

        // Get all emails for this bulk job
        $emails = EmailVerification::where('bulk_verification_job_id', $bulkJob->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($verification) {
                // Build checks array from individual columns
                $checks = [
                    'syntax' => $verification->syntax ?? false,
                    'mx_record' => $verification->mx_record ?? false,
                    'smtp' => $verification->smtp ?? false,
                    'disposable' => $verification->disposable ?? false,
                    'role' => $verification->role ?? false,
                    'no_reply' => $verification->no_reply ?? false,
                    'typo_domain' => $verification->typo_domain ?? false,
                    'mailbox_full' => $verification->mailbox_full ?? false,
                    'is_free' => $verification->is_free ?? false,
                    'blacklist' => $verification->blacklist ?? false,
                    'domain_validity' => $verification->domain_validity ?? false,
                    'isp_esp' => $verification->isp_esp ?? false,
                    'government_tld' => $verification->government_tld ?? false,
                    'ai_analysis' => $verification->ai_analysis ?? false,
                    'did_you_mean' => $verification->did_you_mean ?? null,
                ];
                
                return [
                    'id' => $verification->uuid,
                    'email' => $verification->email,
                    'state' => $verification->state,
                    'result' => $verification->result,
                    'score' => $verification->score,
                    'checks' => $checks,
                    'ai_confidence' => $verification->ai_confidence ?? null,
                    'ai_insights' => $verification->ai_insights ?? null,
                    'created_at' => $verification->created_at,
                ];
            });

        return response()->json($emails);
    }

    public function bulkJobDetail(BulkVerificationJob $bulkJob, Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json(['error' => 'No team selected'], 403);
        }

        // Verify the bulk job belongs to the team
        $bulkJob = BulkVerificationJob::where('uuid', $bulkJob->uuid)
            ->where('team_id', $teamId)
            ->firstOrFail();

        // Get stats for this bulk job
        $stats = EmailVerification::where('bulk_verification_job_id', $bulkJob->id)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as invalid,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as risky
            ', ['deliverable', 'undeliverable', 'risky'])
            ->first();

        $percentages = [
            'valid' => $stats->total > 0 ? round(($stats->valid / $stats->total) * 100, 2) : 0,
            'invalid' => $stats->total > 0 ? round(($stats->invalid / $stats->total) * 100, 2) : 0,
            'risky' => $stats->total > 0 ? round(($stats->risky / $stats->total) * 100, 2) : 0,
        ];

        return response()->json([
            'bulk_job' => [
                'id' => $bulkJob->uuid,
                'filename' => $bulkJob->filename,
                'source' => $bulkJob->source,
                'status' => $bulkJob->status,
                'total_emails' => $bulkJob->total_emails,
                'processed_emails' => $bulkJob->processed_emails ?? 0,
                'progress_percentage' => $bulkJob->progress_percentage,
                'created_at' => $bulkJob->created_at?->toIso8601String(),
                'completed_at' => $bulkJob->completed_at?->toIso8601String(),
            ],
            'stats' => [
                'total' => $stats->total ?? 0,
                'valid' => $stats->valid ?? 0,
                'invalid' => $stats->invalid ?? 0,
                'risky' => $stats->risky ?? 0,
                'percentages' => $percentages,
            ],
        ]);
    }

    public function bulkJobEmailsPaginated(BulkVerificationJob $bulkJob, Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json(['error' => 'No team selected'], 403);
        }

        // Log for debugging
        \Log::info('bulkJobEmailsPaginated called', [
            'uuid' => $bulkJob->uuid ?? 'null',
            'id' => $bulkJob->id ?? 'null',
            'team_id' => $teamId,
        ]);

        // Verify the bulk job belongs to the team
        $bulkJob = BulkVerificationJob::where('uuid', $bulkJob->uuid)
            ->where('team_id', $teamId)
            ->firstOrFail();

        $perPage = $request->get('per_page', 50);
        $page = $request->get('page', 1);

        // Log email count
        $emailCount = EmailVerification::where('bulk_verification_job_id', $bulkJob->id)->count();
        \Log::info('Email count for bulk job', [
            'bulk_job_id' => $bulkJob->id,
            'email_count' => $emailCount,
        ]);

        $emails = EmailVerification::where('bulk_verification_job_id', $bulkJob->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = $emails->map(function ($verification) {
            // Build checks array from individual columns
            $checks = [
                'syntax' => $verification->syntax ?? false,
                'mx_record' => $verification->mx_record ?? false,
                'smtp' => $verification->smtp ?? false,
                'disposable' => $verification->disposable ?? false,
                'role' => $verification->role ?? false,
                'no_reply' => $verification->no_reply ?? false,
                'typo_domain' => $verification->typo_domain ?? false,
                'mailbox_full' => $verification->mailbox_full ?? false,
                'is_free' => $verification->is_free ?? false,
                'blacklist' => $verification->blacklist ?? false,
                'domain_validity' => $verification->domain_validity ?? false,
                'isp_esp' => $verification->isp_esp ?? false,
                'government_tld' => $verification->government_tld ?? false,
                'ai_analysis' => $verification->ai_analysis ?? false,
                'did_you_mean' => $verification->did_you_mean ?? null,
            ];
            
            return [
                'id' => $verification->uuid,
                'email' => $verification->email,
                'state' => $verification->state,
                'result' => $verification->result,
                'score' => $verification->score,
                'checks' => $checks,
                'ai_confidence' => $verification->ai_confidence ?? null,
                'ai_insights' => $verification->ai_insights ?? null,
                'created_at' => $verification->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $data,
            'pagination' => [
                'current_page' => $emails->currentPage(),
                'last_page' => $emails->lastPage(),
                'per_page' => $emails->perPage(),
                'total' => $emails->total(),
            ],
        ]);
    }

    public function verificationDetail(EmailVerification $verification, Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $teamId = $team?->id;

        if (!$teamId) {
            return response()->json(['error' => 'No team selected'], 403);
        }

        // Verify the verification belongs to the team
        $emailVerification = EmailVerification::where('uuid', $verification->uuid)
            ->where('team_id', $teamId)
            ->firstOrFail();

        // Build checks array from individual columns
        $checks = [
            'syntax' => $emailVerification->syntax ?? false,
            'mx_record' => $emailVerification->mx_record ?? false,
            'smtp' => $emailVerification->smtp ?? false,
            'disposable' => $emailVerification->disposable ?? false,
            'role' => $emailVerification->role ?? false,
            'no_reply' => $emailVerification->no_reply ?? false,
            'typo_domain' => $emailVerification->typo_domain ?? false,
            'mailbox_full' => $emailVerification->mailbox_full ?? false,
            'is_free' => $emailVerification->is_free ?? false,
            'blacklist' => $emailVerification->blacklist ?? false,
            'domain_validity' => $emailVerification->domain_validity ?? false,
            'isp_esp' => $emailVerification->isp_esp ?? false,
            'government_tld' => $emailVerification->government_tld ?? false,
            'ai_analysis' => $emailVerification->ai_analysis ?? false,
            'did_you_mean' => $emailVerification->did_you_mean ?? null,
        ];

        return response()->json([
            'verification' => [
                'id' => $emailVerification->uuid,
                'email' => $emailVerification->email,
                'state' => $emailVerification->state,
                'result' => $emailVerification->result,
                'score' => $emailVerification->score,
                'checks' => $checks,
                'ai_analysis' => $emailVerification->ai_analysis ?? false,
                'ai_confidence' => $emailVerification->ai_confidence ?? null,
                'ai_insights' => $emailVerification->ai_insights ?? null,
                'ai_risk_factors' => $emailVerification->ai_risk_factors ?? null,
                'source' => $emailVerification->source,
                'created_at' => $emailVerification->created_at?->toIso8601String(),
                'updated_at' => $emailVerification->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
