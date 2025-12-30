<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blacklists', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique()->comment('Email address or domain to blacklist');
            $table->enum('type', ['email', 'domain'])->default('email')->comment('Type: email or domain');
            $table->enum('reason', ['spamtrap', 'abuse', 'do_not_mail', 'bounce', 'complaint', 'other'])->default('other')->comment('Reason for blacklisting');
            $table->text('notes')->nullable()->comment('Additional notes');
            $table->boolean('active')->default(true)->comment('Whether this blacklist entry is active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'active']);
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blacklists');
    }
};
