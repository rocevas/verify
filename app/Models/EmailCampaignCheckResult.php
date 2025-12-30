<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCampaignCheckResult extends Model
{
    protected $fillable = [
        'email_campaign_id',
        'check_type',
        'spam_score',
        'spam_threshold',
        'is_spam',
        'spam_rules',
        'check_details',
        'deliverability_score',
        'recommendations',
        'checked_at',
    ];

    protected $casts = [
        'spam_score' => 'decimal:2',
        'spam_threshold' => 'decimal:2',
        'is_spam' => 'boolean',
        'spam_rules' => 'array',
        'check_details' => 'array',
        'deliverability_score' => 'array',
        'checked_at' => 'datetime',
    ];

    public function emailCampaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class);
    }
}
