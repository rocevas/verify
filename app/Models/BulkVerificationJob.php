<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BulkVerificationJob extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'team_id',
        'api_key_id',
        'filename',
        'file_path',
        'total_emails',
        'processed_emails',
        'valid_count',
        'invalid_count',
        'risky_count',
        'status',
        'error',
        'result_file_path',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    // api_key_id now stores Sanctum token ID for reference
    // No relationship needed as it's just a reference

    public function getProgressPercentageAttribute(): float
    {
        if ($this->total_emails === 0) {
            return 0;
        }

        return ($this->processed_emails / $this->total_emails) * 100;
    }
}
