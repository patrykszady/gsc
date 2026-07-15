<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Homeowner contact for the automated post-completion review request.
            // Never rendered publicly — admin + mail only.
            $table->string('client_name')->nullable()->after('location');
            $table->string('client_email')->nullable()->after('client_name');
            $table->timestamp('review_request_sent_at')->nullable()->after('client_email');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['client_name', 'client_email', 'review_request_sent_at']);
        });
    }
};
