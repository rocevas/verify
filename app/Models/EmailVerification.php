<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailVerification extends Model
{
    use HasUuids;

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
        'score', // Final score (email_score + ai_confidence if AI is used, otherwise email_score)
        'email_score', // Traditional email verification score (MX, blacklist, SMTP checks, etc.)
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
        'gravatar',
        'did_you_mean',
        'alias_of',
        'verified_at',
        'duration', // Verification duration in seconds (rounded to 2 decimal places)
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
        'gravatar' => 'boolean',
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

    /**
     * Get the final score (combined email_score + AI score)
     * If score is not set, calculates it from email_score + ai_confidence
     */
    public function getScoreAttribute($value)
    {
        // If score is already set, return it
        if ($value !== null) {
            return $value;
        }

        // Calculate score from email_score + ai_confidence (AI)
        $emailScore = $this->attributes['email_score'] ?? 0;
        $aiConfidence = $this->attributes['ai_confidence'] ?? null;

        if ($aiConfidence !== null) {
            // Combine: 70% email_score + 30% AI
            return (int) round(($emailScore * 0.7) + ($aiConfidence * 0.3));
        }

        // If no AI confidence, score equals email_score
        return $emailScore;
    }

    // api_key_id now stores Sanctum token ID for reference
    // No relationship needed as it's just a reference
}
