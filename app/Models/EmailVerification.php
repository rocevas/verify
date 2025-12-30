<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailVerification extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'team_id',
        'api_key_id',
        'bulk_verification_job_id',
        'source',
        'email',
        'account',
        'domain',
        'status',
        'checks',
        'score',
        'error',
        'verified_at',
    ];

    protected $casts = [
        'checks' => 'array',
        'verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function bulkVerificationJob(): BelongsTo
    {
        return $this->belongsTo(BulkVerificationJob::class);
    }

    // api_key_id now stores Sanctum token ID for reference
    // No relationship needed as it's just a reference
}
