<?php

namespace App\Services;

use App\Models\DmarcMonitor;
use App\Models\MonitorCheckResult;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class DmarcReportParserService
{
    /**
     * Parse DMARC aggregate report XML
     */
    public function parse(string $xmlContent): ?array
    {
        try {
            // Suppress XML errors and parse
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xmlContent);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                Log::error('Failed to parse DMARC XML', [
                    'errors' => array_map(fn($e) => $e->message, $errors),
                ]);
                libxml_clear_errors();
                return null;
            }

            // Parse report metadata
            $metadata = $xml->report_metadata ?? null;
            $policy = $xml->policy_published ?? null;

            if (!$metadata) {
                Log::error('DMARC report missing metadata');
                return null;
            }

            $reportData = [
                'report_id' => (string) ($metadata->report_id ?? ''),
                'org_name' => (string) ($metadata->org_name ?? ''),
                'email' => (string) ($metadata->email ?? ''),
                'date_range' => [
                    'begin' => (int) ($metadata->date_range->begin ?? 0),
                    'end' => (int) ($metadata->date_range->end ?? 0),
                ],
                'domain' => (string) ($policy->domain ?? ''),
                'policy' => [
                    'adkim' => (string) ($policy->adkim ?? ''),
                    'aspf' => (string) ($policy->aspf ?? ''),
                    'p' => (string) ($policy->p ?? ''),
                    'sp' => (string) ($policy->sp ?? ''),
                    'pct' => (int) ($policy->pct ?? 0),
                ],
                'records' => [],
            ];

            // Parse records
            if (isset($xml->record)) {
                foreach ($xml->record as $record) {
                    $row = $record->row ?? null;
                    $identifiers = $record->identifiers ?? null;
                    $authResults = $record->auth_results ?? null;

                    if (!$row) {
                        continue;
                    }

                    $recordData = [
                        'source_ip' => (string) ($row->source_ip ?? ''),
                        'count' => (int) ($row->count ?? 0),
                        'policy_evaluated' => [
                            'disposition' => (string) ($row->policy_evaluated->disposition ?? ''),
                            'dkim' => (string) ($row->policy_evaluated->dkim ?? ''),
                            'spf' => (string) ($row->policy_evaluated->spf ?? ''),
                        ],
                        'identifiers' => [
                            'header_from' => (string) ($identifiers->header_from ?? ''),
                        ],
                        'auth_results' => [
                            'dkim' => [
                                'domain' => (string) ($authResults->dkim->domain ?? ''),
                                'result' => (string) ($authResults->dkim->result ?? ''),
                            ],
                            'spf' => [
                                'domain' => (string) ($authResults->spf->domain ?? ''),
                                'result' => (string) ($authResults->spf->result ?? ''),
                            ],
                        ],
                    ];

                    $reportData['records'][] = $recordData;
                }
            }

            return $reportData;

        } catch (\Exception $e) {
            Log::error('Exception parsing DMARC report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Store parsed DMARC report data
     */
    public function storeReport(DmarcMonitor $monitor, array $reportData): void
    {
        // Calculate summary statistics
        $totalMessages = 0;
        $passedCount = 0;
        $failedCount = 0;
        $quarantinedCount = 0;
        $rejectedCount = 0;

        foreach ($reportData['records'] as $record) {
            $count = $record['count'] ?? 0;
            $totalMessages += $count;

            $disposition = $record['policy_evaluated']['disposition'] ?? '';
            switch ($disposition) {
                case 'none':
                    $passedCount += $count;
                    break;
                case 'quarantine':
                    $quarantinedCount += $count;
                    break;
                case 'reject':
                    $rejectedCount += $count;
                    break;
                default:
                    $failedCount += $count;
            }
        }

        // Check if there are issues
        $hasIssue = $quarantinedCount > 0 || $rejectedCount > 0 || $failedCount > 0;

        // Create or update check result
        MonitorCheckResult::create([
            'monitor_type' => 'dmarc_monitor',
            'monitor_id' => $monitor->id,
            'has_issue' => $hasIssue,
            'check_details' => [
                'report_id' => $reportData['report_id'],
                'org_name' => $reportData['org_name'],
                'date_range' => $reportData['date_range'],
                'summary' => [
                    'total_messages' => $totalMessages,
                    'passed' => $passedCount,
                    'failed' => $failedCount,
                    'quarantined' => $quarantinedCount,
                    'rejected' => $rejectedCount,
                ],
                'records' => $reportData['records'],
            ],
            'checked_at' => now(),
        ]);

        // Update monitor if there are issues
        if ($hasIssue && !$monitor->has_issue) {
            $monitor->update([
                'has_issue' => true,
                'last_check_details' => [
                    'message' => "DMARC issues detected: {$quarantinedCount} quarantined, {$rejectedCount} rejected",
                    'summary' => [
                        'total_messages' => $totalMessages,
                        'quarantined' => $quarantinedCount,
                        'rejected' => $rejectedCount,
                    ],
                ],
            ]);
        } elseif (!$hasIssue && $monitor->has_issue) {
            $monitor->update([
                'has_issue' => false,
                'last_check_details' => [
                    'message' => 'DMARC reports show no issues',
                ],
            ]);
        }

        // Update last checked time
        $monitor->update([
            'last_checked_at' => now(),
        ]);
    }
}

