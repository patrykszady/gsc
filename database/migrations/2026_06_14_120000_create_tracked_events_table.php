<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_events', function (Blueprint $table) {
            $table->id();
            // phone_click | email_click | form_submit | cta_click
            $table->string('type', 40);
            // The clicked value (phone number, email) or form name
            $table->string('label')->nullable();
            $table->string('page_path')->nullable();
            $table->string('referrer')->nullable();
            $table->string('session_id', 64)->nullable();
            $table->string('country', 8)->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('created_at');
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_events');
    }
};
