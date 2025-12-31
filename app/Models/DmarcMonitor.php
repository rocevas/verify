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
        'domain',
        'report_email',
        'active',
        'last_checked_at',
        'has_issue',
        'last_check_details',
        'dmarc_record',
        'dmarc_record_string',
    ];

    protected $casts = [
        'active' => 'boolean',
        'has_issue' => 'boolean',
        'last_checked_at' => 'datetime',
        'last_check_details' => 'array',
        'dmarc_record' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (DmarcMonitor $monitor) {
            // Auto-generate report_email if not provided
            if (!$monitor->report_email) {
                $monitor->report_email = $monitor->generateReportEmail();
            }
            
            // Set active to true by default
            if ($monitor->active === null) {
                $monitor->active = true;
            }
        });

        static::created(function (DmarcMonitor $monitor) {
            // Generate DMARC record string after creation
            $monitor->generateDmarcRecord();
        });
    }

    /**
     * Generate report email address for this monitor
     */
    public function generateReportEmail(): string
    {
        $domain = parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost';
        $hash = substr(md5($this->domain . $this->user_id), 0, 8);
        return "dmarc-{$hash}@{$domain}";
    }

    /**
     * Generate DMARC record string
     */
    public function generateDmarcRecord(): string
    {
        $reportEmail = $this->report_email ?? $this->generateReportEmail();
        $dmarcRecord = sprintf(
            'v=DMARC1; p=none; pct=100; rua=mailto:%s',
            $reportEmail
        );
        
        // Only update if model exists
        if ($this->exists) {
            $this->update([
                'dmarc_record_string' => $dmarcRecord,
                'report_email' => $reportEmail,
            ]);
        } else {
            // For new models, set attributes directly
            $this->dmarc_record_string = $dmarcRecord;
            $this->report_email = $reportEmail;
        }
        
        return $dmarcRecord;
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
            ->where('monitor_type', 'dmarc_monitor')
            ->orderBy('checked_at', 'desc');
    }
}
