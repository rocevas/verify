<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CheckBlocklistMonitorJob;
use App\Jobs\CheckDmarcMonitorJob;
use App\Models\BlocklistMonitor;
use App\Models\DmarcMonitor;
use App\Models\MonitorCheckResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MonitorController extends Controller
{
    /**
     * Get all blocklist monitors
     */
    public function blocklistIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        
        $query = BlocklistMonitor::where('user_id', $user->id);
        
        if ($team) {
            $query->where(function ($q) use ($team) {
                $q->where('team_id', $team->id)
                  ->orWhereNull('team_id');
            });
        } else {
            $query->whereNull('team_id');
        }
        
        $monitors = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json($monitors);
    }

    /**
     * Get all DMARC monitors
     */
    public function dmarcIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        
        $query = DmarcMonitor::where('user_id', $user->id);
        
        if ($team) {
            $query->where(function ($q) use ($team) {
                $q->where('team_id', $team->id)
                  ->orWhereNull('team_id');
            });
        } else {
            $query->whereNull('team_id');
        }
        
        $monitors = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json($monitors);
    }

    /**
     * Create blocklist monitor
     */
    public function blocklistStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'target' => 'required|string|max:255',
            'type' => 'sometimes|in:domain,ip',
            'active' => 'boolean',
            'check_interval_minutes' => 'sometimes|integer|min:5|max:1440',
            'team_id' => 'nullable|exists:teams,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $team = $user->currentTeam;
        $target = $request->input('target');
        
        // Auto-detect type if not provided
        $type = $request->input('type');
        if (!$type) {
            $type = filter_var($target, FILTER_VALIDATE_IP) ? 'ip' : 'domain';
        }

        $monitor = BlocklistMonitor::create([
            'user_id' => $user->id,
            'team_id' => $request->input('team_id') ?? $team?->id,
            'name' => $target, // Auto-set name from target
            'type' => $type,
            'target' => $target,
            'active' => $request->input('active', true),
            'check_interval_minutes' => $request->input('check_interval_minutes', 1440),
        ]);

        // Automatically dispatch check job after creation
        CheckBlocklistMonitorJob::dispatch($monitor->id);

        return response()->json($monitor, 201);
    }

    /**
     * Create DMARC monitor
     */
    public function dmarcStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'domain' => 'required|string|max:255|regex:/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/i',
            'report_email' => 'nullable|email|max:255',
            'active' => 'boolean',
            'team_id' => 'nullable|exists:teams,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $team = $user->currentTeam;

        $monitor = DmarcMonitor::create([
            'user_id' => $user->id,
            'team_id' => $request->input('team_id') ?? $team?->id,
            'domain' => $request->input('domain'),
            'report_email' => $request->input('report_email'), // Will be auto-generated if null
            'active' => $request->input('active', true),
        ]);

        // Ensure DMARC record is generated
        if (!$monitor->dmarc_record_string) {
            $monitor->generateDmarcRecord();
            $monitor->refresh();
        }

        return response()->json($monitor, 201);
    }

    /**
     * Update blocklist monitor
     */
    public function blocklistUpdate(Request $request, int $id): JsonResponse
    {
        $monitor = BlocklistMonitor::where('user_id', $request->user()->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'type' => 'sometimes|in:domain,ip',
            'target' => 'sometimes|string|max:255',
            'active' => 'boolean',
            'check_interval_minutes' => 'sometimes|integer|min:5|max:1440',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $updateData = $request->only(['type', 'target', 'active', 'check_interval_minutes']);
        
        // Auto-detect type if target changed and type not provided
        if (isset($updateData['target']) && !isset($updateData['type'])) {
            $updateData['type'] = filter_var($updateData['target'], FILTER_VALIDATE_IP) ? 'ip' : 'domain';
        }
        
        // Auto-set name from target if target changed
        if (isset($updateData['target'])) {
            $updateData['name'] = $updateData['target'];
        }

        $monitor->update($updateData);

        return response()->json($monitor);
    }

    /**
     * Update DMARC monitor
     */
    public function dmarcUpdate(Request $request, int $id): JsonResponse
    {
        $monitor = DmarcMonitor::where('user_id', $request->user()->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'domain' => 'sometimes|string|max:255|regex:/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/i',
            'report_email' => 'nullable|email|max:255',
            'active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $updateData = $request->only(['domain', 'report_email', 'active']);
        
        // If domain or report_email changed, regenerate DMARC record
        if (isset($updateData['domain']) || isset($updateData['report_email'])) {
            $monitor->update($updateData);
            $monitor->generateDmarcRecord();
            $monitor->refresh();
        } else {
            $monitor->update($updateData);
        }

        return response()->json($monitor);
    }

    /**
     * Delete blocklist monitor
     */
    public function blocklistDestroy(Request $request, int $id): JsonResponse
    {
        $monitor = BlocklistMonitor::where('user_id', $request->user()->id)->findOrFail($id);
        $monitor->delete();

        return response()->json(['message' => 'Monitor deleted successfully']);
    }

    /**
     * Delete DMARC monitor
     */
    public function dmarcDestroy(Request $request, int $id): JsonResponse
    {
        $monitor = DmarcMonitor::where('user_id', $request->user()->id)->findOrFail($id);
        $monitor->delete();

        return response()->json(['message' => 'Monitor deleted successfully']);
    }

    /**
     * Check blocklist monitor now
     */
    public function blocklistCheckNow(Request $request, int $id): JsonResponse
    {
        $monitor = BlocklistMonitor::where('user_id', $request->user()->id)->findOrFail($id);
        
        CheckBlocklistMonitorJob::dispatch($monitor->id);

        return response()->json(['message' => 'Check queued successfully']);
    }

    /**
     * Check DMARC monitor now
     */
    public function dmarcCheckNow(Request $request, int $id): JsonResponse
    {
        $monitor = DmarcMonitor::where('user_id', $request->user()->id)->findOrFail($id);
        
        CheckDmarcMonitorJob::dispatch($monitor->id);

        return response()->json(['message' => 'Check queued successfully']);
    }

    /**
     * Get check results for a monitor
     */
    public function checkResults(Request $request, string $type, int $id): JsonResponse
    {
        $user = $request->user();
        
        $monitorType = $type === 'blocklist' ? 'blocklist_monitor' : 'dmarc_monitor';
        
        // Verify monitor belongs to user
        if ($type === 'blocklist') {
            $monitor = BlocklistMonitor::where('user_id', $user->id)->findOrFail($id);
        } else {
            $monitor = DmarcMonitor::where('user_id', $user->id)->findOrFail($id);
        }
        
        $results = MonitorCheckResult::where('monitor_type', $monitorType)
            ->where('monitor_id', $id)
            ->orderBy('checked_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json($results);
    }

    /**
     * Get DMARC monitor detail with reports
     */
    public function dmarcDetail(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        
        $monitor = DmarcMonitor::where('user_id', $user->id)
            ->with(['checkResults' => function ($query) {
                $query->orderBy('checked_at', 'desc')->limit(100);
            }])
            ->findOrFail($id);

        // Calculate statistics from reports
        $stats = [
            'total_reports' => $monitor->checkResults->count(),
            'total_messages' => 0,
            'passed' => 0,
            'failed' => 0,
            'quarantined' => 0,
            'rejected' => 0,
            'unique_ips' => 0,
            'reports_by_date' => [],
        ];

        $uniqueIPs = [];
        foreach ($monitor->checkResults as $result) {
            $details = $result->check_details ?? [];
            if (isset($details['summary'])) {
                $summary = $details['summary'];
                $stats['total_messages'] += $summary['total_messages'] ?? 0;
                $stats['passed'] += $summary['passed'] ?? 0;
                $stats['failed'] += $summary['failed'] ?? 0;
                $stats['quarantined'] += $summary['quarantined'] ?? 0;
                $stats['rejected'] += $summary['rejected'] ?? 0;
            }

            // Collect unique IPs
            if (isset($details['records'])) {
                foreach ($details['records'] as $record) {
                    if (isset($record['source_ip'])) {
                        $uniqueIPs[$record['source_ip']] = true;
                    }
                }
            }

            // Group by date
            $date = $result->checked_at->format('Y-m-d');
            if (!isset($stats['reports_by_date'][$date])) {
                $stats['reports_by_date'][$date] = 0;
            }
            $stats['reports_by_date'][$date]++;
        }

        $stats['unique_ips'] = count($uniqueIPs);

        return response()->json([
            'monitor' => $monitor,
            'stats' => $stats,
            'reports' => $monitor->checkResults->map(function ($result) {
                return [
                    'id' => $result->id,
                    'checked_at' => $result->checked_at,
                    'has_issue' => $result->has_issue,
                    'check_details' => $result->check_details,
                ];
            }),
        ]);
    }
}
