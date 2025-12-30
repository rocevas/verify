<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignCheckResult;
use App\Services\SpamAssassinCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmailCampaignController extends Controller
{
    public function __construct(
        private SpamAssassinCheckService $spamAssassinService
    ) {
    }

    /**
     * Get all campaigns
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        
        $query = EmailCampaign::where('user_id', $user->id);
        
        if ($team) {
            $query->where(function ($q) use ($team) {
                $q->where('team_id', $team->id)
                  ->orWhereNull('team_id');
            });
        } else {
            $query->whereNull('team_id');
        }
        
        $campaigns = $query->with('latestCheckResult')->orderBy('created_at', 'desc')->get();
        
        return response()->json($campaigns);
    }

    /**
     * Create campaign
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'html_content' => 'nullable|string',
            'text_content' => 'nullable|string',
            'from_email' => 'required|email|max:255',
            'from_name' => 'nullable|string|max:255',
            'reply_to' => 'nullable|email|max:255',
            'to_emails' => 'nullable|array',
            'to_emails.*' => 'email',
            'headers' => 'nullable|array',
            'team_id' => 'nullable|exists:teams,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $team = $user->currentTeam;

        $campaign = EmailCampaign::create([
            'user_id' => $user->id,
            'team_id' => $request->input('team_id') ?? $team?->id,
            'name' => $request->input('name'),
            'subject' => $request->input('subject'),
            'html_content' => $request->input('html_content'),
            'text_content' => $request->input('text_content'),
            'from_email' => $request->input('from_email'),
            'from_name' => $request->input('from_name'),
            'reply_to' => $request->input('reply_to'),
            'to_emails' => $request->input('to_emails'),
            'headers' => $request->input('headers'),
        ]);

        // Automatically start check after creation (with timeout protection)
        // Don't block the response - check will happen in background or user can trigger manually
        try {
            // Set timeout to prevent blocking
            set_time_limit(60);
            
            $result = $this->spamAssassinService->checkCampaign($campaign);
            
            EmailCampaignCheckResult::create([
                'email_campaign_id' => $campaign->id,
                'check_type' => 'spamassassin',
                'spam_score' => $result['spam_score'],
                'spam_threshold' => $result['spam_threshold'],
                'is_spam' => $result['is_spam'],
                'spam_rules' => $result['spam_rules'],
                'check_details' => $result['check_details'],
                'deliverability_score' => $result['deliverability_score'],
                'recommendations' => $result['recommendations'],
                'checked_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail campaign creation
            // This allows the campaign to be created even if check fails
            \Log::error('Failed to auto-check campaign after creation', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Reload campaign with latest check result
        $campaign->refresh();
        $campaign->load('latestCheckResult');
        
        return response()->json($campaign, 201);
    }

    /**
     * Get campaign
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $campaign = EmailCampaign::where('user_id', $request->user()->id)
            ->with('latestCheckResult')
            ->findOrFail($id);

        return response()->json($campaign);
    }

    /**
     * Update campaign
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $campaign = EmailCampaign::where('user_id', $request->user()->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'subject' => 'sometimes|string|max:255',
            'html_content' => 'nullable|string',
            'text_content' => 'nullable|string',
            'from_email' => 'sometimes|email|max:255',
            'from_name' => 'nullable|string|max:255',
            'reply_to' => 'nullable|email|max:255',
            'to_emails' => 'nullable|array',
            'to_emails.*' => 'email',
            'headers' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $campaign->update($request->only([
            'name', 'subject', 'html_content', 'text_content',
            'from_email', 'from_name', 'reply_to', 'to_emails', 'headers'
        ]));

        return response()->json($campaign);
    }

    /**
     * Delete campaign
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $campaign = EmailCampaign::where('user_id', $request->user()->id)->findOrFail($id);
        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted successfully']);
    }

    /**
     * Check campaign
     */
    public function check(Request $request, int $id): JsonResponse
    {
        $campaign = EmailCampaign::where('user_id', $request->user()->id)->findOrFail($id);

        try {
            // Set max execution time for this request
            set_time_limit(60);
            
            $result = $this->spamAssassinService->checkCampaign($campaign);
            
            $checkResult = EmailCampaignCheckResult::create([
                'email_campaign_id' => $campaign->id,
                'check_type' => 'spamassassin',
                'spam_score' => $result['spam_score'],
                'spam_threshold' => $result['spam_threshold'],
                'is_spam' => $result['is_spam'],
                'spam_rules' => $result['spam_rules'],
                'check_details' => $result['check_details'],
                'deliverability_score' => $result['deliverability_score'],
                'recommendations' => $result['recommendations'],
                'checked_at' => now(),
            ]);

            return response()->json($checkResult);
        } catch (\Exception $e) {
            \Log::error('SpamAssassin check failed in controller', [
                'campaign_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'error' => 'Failed to check campaign: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get check results for campaign
     */
    public function checkResults(Request $request, int $id): JsonResponse
    {
        $campaign = EmailCampaign::where('user_id', $request->user()->id)->findOrFail($id);
        
        $results = $campaign->checkResults()->orderBy('checked_at', 'desc')->get();
        
        return response()->json($results);
    }
}
