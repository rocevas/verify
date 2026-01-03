<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $shared = [
            ...parent::share($request),
        ];

        // Add recent verifications for sidebar (only for authenticated users)
        if ($request->user()) {
            $team = $request->user()->currentTeam;
            $teamId = $team?->id;

            if ($teamId) {
                // Get bulk jobs with their stats
                $bulkJobs = \App\Models\BulkVerificationJob::where('team_id', $teamId)
                    ->orderBy('created_at', 'desc')
                    ->limit(20)
                    ->get();

                // Get all stats in one query to avoid N+1 problem
                $bulkJobIds = $bulkJobs->pluck('id');
                $statsQuery = \App\Models\EmailVerification::whereIn('bulk_verification_job_id', $bulkJobIds)
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

                // Get individual verifications (not part of bulk jobs)
                $individualVerifications = \App\Models\EmailVerification::where('team_id', $teamId)
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

                $shared['sidebarVerifications'] = [
                    'bulk_jobs' => $bulkJobs,
                    'individual_verifications' => $individualVerifications,
                ];
            } else {
                $shared['sidebarVerifications'] = [
                    'bulk_jobs' => [],
                    'individual_verifications' => [],
                ];
            }
        }

        return $shared;
    }
}
