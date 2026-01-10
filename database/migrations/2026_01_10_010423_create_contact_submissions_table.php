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
        Schema::create('contact_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 20);
            $table->string('address')->nullable();
            $table->text('message')->nullable();
            $table->string('availability')->nullable();
            $table->string('city')->nullable(); // Area served city
            $table->string('source')->default('web'); // web, mobile, etc.
            $table->string('referrer')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->timestamps();
            
            $table->index('created_at');
            $table->index('email');
            $table->index('city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_submissions');
    }
};
