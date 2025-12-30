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
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('name')->comment('Campaign name');
            $table->string('subject')->comment('Email subject');
            $table->text('html_content')->nullable()->comment('HTML email content');
            $table->text('text_content')->nullable()->comment('Plain text email content');
            $table->string('from_email')->comment('From email address');
            $table->string('from_name')->nullable()->comment('From name');
            $table->string('reply_to')->nullable()->comment('Reply-to email address');
            $table->json('to_emails')->nullable()->comment('Test recipient emails');
            $table->json('headers')->nullable()->comment('Custom email headers');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_campaigns');
    }
};
