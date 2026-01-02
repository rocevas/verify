<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class MxSkipList extends Model
{
    protected $table = 'mx_skip_list';

    protected $fillable = [
        'mx_host',
        'reason',
        'response',
        'failure_count',
        'last_failed_at',
        'expires_at',
        'is_manual',
    ];

    protected $casts = [
        'last_failed_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_manual' => 'boolean',
        'failure_count' => 'integer',
    ];

    /**
     * Scope to get only active (non-expired) entries
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Scope to get only auto-added entries (for cleanup)
     */
    public function scopeAutoAdded(Builder $query): Builder
    {
        return $query->where('is_manual', false);
    }

    /**
     * Check if MX host is in skip list
     */
    public static function isSkipped(string $mxHost): bool
    {
        $mxHostLower = strtolower($mxHost);
        
        return self::active()
            ->where('mx_host', $mxHostLower)
            ->exists();
    }

    /**
     * Add or update MX host in skip list
     */
    public static function addOrUpdate(
        string $mxHost,
        string $reason,
        ?string $response = null,
        bool $isManual = false,
        ?int $expiresInDays = 30
    ): self {
        $mxHostLower = strtolower($mxHost);
        
        // Use firstOrNew to get or create entry
        $entry = self::firstOrNew(['mx_host' => $mxHostLower]);
        
        // Update fields
        $entry->reason = $reason;
        $entry->response = $response;
        $entry->is_manual = $isManual;
        $entry->last_failed_at = now();
        
        // Increment failure_count (handle null case)
        $entry->failure_count = ($entry->failure_count ?? 0) + 1;
        
        // Set expiration only for auto-added entries
        if (!$isManual && $expiresInDays) {
            $entry->expires_at = now()->addDays($expiresInDays);
        } else {
            $entry->expires_at = null; // Manual entries never expire
        }
        
        $entry->save();
        
        return $entry;
    }

    /**
     * Clean up expired auto-added entries
     */
    public static function cleanupExpired(): int
    {
        return self::autoAdded()
            ->where('expires_at', '<=', now())
            ->delete();
    }

    /**
     * Remove MX host from skip list
     */
    public static function remove(string $mxHost): bool
    {
        return self::where('mx_host', strtolower($mxHost))->delete();
    }
}

