<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BlacklistSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('blacklists')->insert([
            // Fake trap email addresses (from Dotdigital examples)
            [
                'email'      => 'noemail@foryou.com',
                'type'       => 'email',
                'reason'     => 'spamtrap',
                'notes'      => 'Example fake trap address (Dotdigital)',
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'email'      => 'pleasedont@emailme.com',
                'type'       => 'email',
                'reason'     => 'spamtrap',
                'notes'      => 'Another fake trap address (Dotdigital)',
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now()
            ],

            // WHOIS role accounts (common trap candidates)
            [
                'email'      => 'postmaster@example.com',
                'type'       => 'email',
                'reason'     => 'spamtrap',
                'notes'      => 'Role account trap (Spamhaus example)',
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'email'      => 'abuse@example.com',
                'type'       => 'email',
                'reason'     => 'spamtrap',
                'notes'      => 'Role account trap (Spamhaus example)',
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'email'      => 'admin@example.com',
                'type'       => 'email',
                'reason'     => 'spamtrap',
                'notes'      => 'Role account trap (Spamhaus example)',
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now()
            ],

            // Trap domains (typos / fake)
            [
                'email'      => 'gmial.com',
                'type'       => 'domain',
                'reason'     => 'spamtrap',
                'notes'      => 'Typo trap domain (gmail misspelling)',
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'email'      => 'foryou.com',
                'type'       => 'domain',
                'reason'     => 'spamtrap',
                'notes'      => 'Trap domain used in Dotdigital example',
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'email'      => 'emailme.com',
                'type'       => 'domain',
                'reason'     => 'spamtrap',
                'notes'      => 'Trap domain used in Dotdigital example',
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
        ]);
    }
}
