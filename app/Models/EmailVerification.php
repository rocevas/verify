<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class EmailVerification extends Model
{
    use HasUuids, SoftDeletes;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the primary key ID.
     */
    protected $keyType = 'int';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = true;

    /**
     * Get the columns that should receive a unique identifier.
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'user_id',
        'team_id',
        'api_key_id',
        'bulk_verification_job_id',
        'source',
        'email',
        'account',
        'domain',
        'state', // enum: deliverable, undeliverable, risky, unknown, error
        'result', // string: valid, syntax_error, typo, mailbox_not_found, disposable, blocked, catch_all, mailbox_full, role, error
        'score',
        'ai_analysis',
        'ai_insights',
        'ai_confidence',
        'ai_risk_factors',
        'blacklist',
        'domain_validity',
        'syntax',
        'mx_record',
        'smtp',
        'disposable',
        'role',
        'no_reply',
        'typo_domain',
        'mailbox_full',
        'is_free',
        'isp_esp',
        'government_tld',
        'did_you_mean',
        'verified_at',
    ];

    protected $casts = [
        'ai_analysis' => 'boolean',
        'blacklist' => 'boolean',
        'domain_validity' => 'boolean',
        'syntax' => 'boolean',
        'mx_record' => 'boolean',
        'smtp' => 'boolean',
        'disposable' => 'boolean',
        'role' => 'boolean',
        'no_reply' => 'boolean',
        'typo_domain' => 'boolean',
        'mailbox_full' => 'boolean',
        'is_free' => 'boolean',
        'isp_esp' => 'boolean',
        'government_tld' => 'boolean',
        'ai_risk_factors' => 'array',
        'verified_at' => 'datetime',
    ];

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Retrieve the model for bound value.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $field = $field ?: $this->getRouteKeyName();
        
        // If field is 'uuid' but value looks like an integer (not a UUID format),
        // try to find by id first for backward compatibility
        // UUID format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx (contains dashes)
        if ($field === 'uuid') {
            // Check if value is numeric and not a valid UUID format
            $isNumeric = is_numeric($value);
            $isValidUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
            
            if ($isNumeric && !$isValidUuid) {
                // Try to find by integer ID first (for backward compatibility)
                $model = $this->where('id', (int)$value)->first();
                if ($model) {
                    return $model;
                }
            }
        }
        
        return $this->where($field, $value)->first();
    }

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
