<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DmarcMonitor extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'team_id',
        'name',
        'domain',
        'report_email',
        'active',
        'check_interval_minutes',
        'last_checked_at',
        'has_issue',
        'last_check_details',
        'dmarc_record',
    ];

    protected $casts = [
        'active' => 'boolean',
        'has_issue' => 'boolean',
        'last_checked_at' => 'datetime',
        'check_interval_minutes' => 'integer',
        'last_check_details' => 'array',
        'dmarc_record' => 'array',
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
        return $this->hasMany(MonitorCheckResult::class, 'monitor_id')
            ->where('monitor_type', 'dmarc_monitor')
            ->orderBy('checked_at', 'desc');
    }
}
