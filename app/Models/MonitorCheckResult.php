<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitorCheckResult extends Model
{
    protected $fillable = [
        'monitor_type',
        'monitor_id',
        'has_issue',
        'check_details',
        'notification_sent',
        'checked_at',
    ];

    protected $casts = [
        'has_issue' => 'boolean',
        'check_details' => 'array',
        'notification_sent' => 'boolean',
        'checked_at' => 'datetime',
    ];
}
