<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailCampaign extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'team_id',
        'name',
        'subject',
        'html_content',
        'text_content',
        'from_email',
        'from_name',
        'reply_to',
        'to_emails',
        'headers',
    ];

    protected $casts = [
        'to_emails' => 'array',
        'headers' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function checkResults(): HasMany
    {
        return $this->hasMany(EmailCampaignCheckResult::class)->orderBy('checked_at', 'desc');
    }

    public function latestCheckResult(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EmailCampaignCheckResult::class)->latestOfMany('checked_at');
    }
}
