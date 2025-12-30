<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;

class EmailCampaignController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('InboxInsight');
    }

    public function check(int $id): Response
    {
        $campaign = EmailCampaign::where('user_id', auth()->id())
            ->with('latestCheckResult')
            ->findOrFail($id);

        // Check if campaign was just created (within last 30 seconds) and has no check result
        $justCreated = $campaign->created_at->gt(now()->subSeconds(30));
        $needsAutoCheck = $justCreated && !$campaign->latestCheckResult;

        return Inertia::render('EmailCampaignCheck', [
            'campaign' => $campaign,
            'autoCheck' => $needsAutoCheck,
        ]);
    }
}
