<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review-request emails are sent from elsewhere now; the homeowner contact
 * columns and the sent-at stamp that fed the in-app mailer go with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['client_name', 'client_email', 'review_request_sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('client_name')->nullable()->after('location');
            $table->string('client_email')->nullable()->after('client_name');
            $table->timestamp('review_request_sent_at')->nullable()->after('client_email');
        });
    }
};
