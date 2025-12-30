<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BlocklistMonitor extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'team_id',
        'name',
        'type',
        'target',
        'active',
        'check_interval_minutes',
        'last_checked_at',
        'is_blocklisted',
        'last_check_details',
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_blocklisted' => 'boolean',
        'last_checked_at' => 'datetime',
        'check_interval_minutes' => 'integer',
        'last_check_details' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (BlocklistMonitor $monitor) {
            // Auto-detect type if not set
            if (!$monitor->type && $monitor->target) {
                $monitor->type = filter_var($monitor->target, FILTER_VALIDATE_IP) ? 'ip' : 'domain';
            }
            
            // Auto-set name from target if not set (for backward compatibility)
            if (!$monitor->name && $monitor->target) {
                $monitor->name = $monitor->target;
            }
            
            // Set default check interval to 1440 minutes (24 hours) if not set
            if (!$monitor->check_interval_minutes) {
                $monitor->check_interval_minutes = 1440;
            }
        });
    }

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
            ->where('monitor_type', 'blocklist_monitor')
            ->orderBy('checked_at', 'desc');
    }
}
