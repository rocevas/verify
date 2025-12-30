<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Blacklist extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'email',
        'type',
        'reason',
        'notes',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Check if an email or domain is blacklisted
     */
    public static function isBlacklisted(string $email): ?self
    {
        // Parse email to get domain
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $domain = $parts[1];

        // Check exact email match
        $blacklist = self::where('email', $email)
            ->where('type', 'email')
            ->where('active', true)
            ->first();

        if ($blacklist) {
            return $blacklist;
        }

        // Check domain match
        $blacklist = self::where('email', $domain)
            ->where('type', 'domain')
            ->where('active', true)
            ->first();

        return $blacklist;
    }
}
