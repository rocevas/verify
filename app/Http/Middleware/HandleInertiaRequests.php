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
                // Get more bulk jobs and individual verifications to ensure we have enough for top 20
                $bulkJobs = \App\Models\BulkVerificationJob::where('team_id', $teamId)
                    ->orderBy('created_at', 'desc')
                    ->limit(30)
                    ->get();

                // Get all stats in one query to avoid N+1 problem
                $bulkJobIds = $bulkJobs->pluck('id');
                $statsQuery = \App\Models\EmailVerification::whereIn('bulk_verification_job_id', $bulkJobIds)
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

                $bulkJobsArray = $bulkJobs->map(function ($bulkJob) use ($statsQuery) {
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
                })->toArray();

                // Get individual verifications (not part of bulk jobs)
                $individualVerificationsArray = \App\Models\EmailVerification::where('team_id', $teamId)
                    ->whereNull('bulk_verification_job_id')
                    ->orderBy('created_at', 'desc')
                    ->limit(30)
                    ->get()
                    ->map(function ($verification) {
                        return [
                            'id' => $verification->uuid,
                            'type' => 'individual',
                            'email' => $verification->email,
                            'state' => $verification->state,
                            'result' => $verification->result,
                            'score' => $verification->score,
                            'checks' => $this->buildChecksArray($verification),
                            'created_at' => $verification->created_at?->toIso8601String() ?? $verification->created_at,
                        ];
                    })->toArray();

                // Combine both arrays
                $combined = array_merge($bulkJobsArray, $individualVerificationsArray);

                // Sort by created_at (newest first)
                usort($combined, function ($a, $b) {
                    $dateA = strtotime($a['created_at'] ?? 0);
                    $dateB = strtotime($b['created_at'] ?? 0);
                    return $dateB <=> $dateA;
                });

                // Take only top 20
                $top20 = array_slice($combined, 0, 20);

                // Separate back into bulk_jobs and individual_verifications
                $bulkJobs = [];
                $individualVerifications = [];

                foreach ($top20 as $item) {
                    if ($item['type'] === 'bulk') {
                        $bulkJobs[] = $item;
                    } else {
                        $individualVerifications[] = $item;
                    }
                }

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

    /**
     * Build checks array from individual columns
     */
    private function buildChecksArray(\App\Models\EmailVerification $verification): array
    {
        return [
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
    }
}
