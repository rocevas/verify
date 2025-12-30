<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Blocklist Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration defines which DNS blocklists (RBL/DNSBL) are checked
    | for each plan type. You can easily add or remove blocklists by modifying
    | the arrays below.
    |
    | Format: 'dnsbl.hostname.com' => 'Display Name'
    |
    */

    'plans' => [
        'free' => [
            // Most popular and reliable blocklists for free plan
            'zen.spamhaus.org' => 'Spamhaus ZEN',
            'bl.spamhaus.org' => 'Spamhaus Block List',
            'bl.spamcop.net' => 'SpamCop',
            'dnsbl.sorbs.net' => 'SORBS',
            'b.barracudacentral.org' => 'Barracuda',
            'cbl.abuseat.org' => 'Abuseat CBL',
        ],

        'paid' => [
            // All free blocklists plus premium ones
            'zen.spamhaus.org' => 'Spamhaus ZEN',
            'bl.spamhaus.org' => 'Spamhaus Block List',
            'pbl.spamhaus.org' => 'Spamhaus Policy Block List',
            'sbl.spamhaus.org' => 'Spamhaus SBL',
            'xbl.spamhaus.org' => 'Spamhaus Exploits Block List',
            'dbl.spamhaus.org' => 'Spamhaus Domain Block List',
            
            // SpamCop
            'bl.spamcop.net' => 'SpamCop',
            
            // SORBS
            'dnsbl.sorbs.net' => 'SORBS',
            'spam.dnsbl.sorbs.net' => 'SORBS Spam',
            'zombie.dnsbl.sorbs.net' => 'SORBS Zombie',
            
            // Barracuda
            'b.barracudacentral.org' => 'Barracuda',
            
            // SURBL
            'multi.surbl.org' => 'SURBL',
            
            // Mailspike
            'bl.mailspike.net' => 'Mailspike',
            
            // SpamRATS
            'dnsbl.spamrats.com' => 'SpamRATS',
            'noptr.spamrats.com' => 'SpamRATS NoPtr',
            
            // Invaluement
            'dnsbl.invaluement.com' => 'Invaluement',
            
            // Spam Eating Monkey
            'bl.spameatingmonkey.net' => 'Spam Eating Monkey',
            
            // UCEPROTECT
            'dnsbl-1.uceprotect.net' => 'UCEPROTECT Level 1',
            'dnsbl-2.uceprotect.net' => 'UCEPROTECT Level 2',
            'dnsbl-3.uceprotect.net' => 'UCEPROTECT Level 3',
            
            // Backscatterer
            'ips.backscatterer.org' => 'Backscatterer',
            
            // DroneBL
            'dnsbl.dronebl.org' => 'DroneBL',
            
            // Abuseat
            'cbl.abuseat.org' => 'Abuseat CBL',
            
            // Lashback
            'ubl.unsubscore.com' => 'Lashback UBL',
            
            // Spam Cannibal
            'bl.spamcannibal.org' => 'Spam Cannibal',
            
            // NJABL
            'dnsbl.njabl.org' => 'NJABL',
            
            // MSRBL
            'combined.rbl.msrbl.net' => 'MSRBL Combined',
            'spam.rbl.msrbl.net' => 'MSRBL Spam',
            'phishing.rbl.msrbl.net' => 'MSRBL Phishing',
            'malware.rbl.msrbl.net' => 'MSRBL Malware',
            
            // Blocklist.de
            'bl.blocklist.de' => 'Blocklist.de',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Plan
    |--------------------------------------------------------------------------
    |
    | The default plan to use when a user's plan cannot be determined.
    |
    */

    'default_plan' => 'free',
];

