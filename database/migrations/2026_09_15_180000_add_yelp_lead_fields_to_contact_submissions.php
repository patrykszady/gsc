<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only: Yelp Request-a-Quote leads arrive through the server's own
 * biz.yelp.com session (yelp:sync-leads) and are stored as submissions like
 * any other lead, so ss.systems shows them first and hive gets them the same
 * way. Yelp never exposes the customer's email or phone — the conversation
 * id and lead id are how a reply finds its way back. Photos the customer
 * attached are copied to the public disk and listed in `attachments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->string('yelp_lead_id', 40)->nullable()->unique()->after('hive_send_error');
            $table->string('yelp_conversation_id', 40)->nullable()->after('yelp_lead_id');
            $table->string('yelp_status', 24)->nullable()->after('yelp_conversation_id');
            $table->timestamp('yelp_last_event_at')->nullable()->after('yelp_status');
            $table->json('attachments')->nullable()->after('yelp_last_event_at');
        });
    }

    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropColumn(['yelp_lead_id', 'yelp_conversation_id', 'yelp_status', 'yelp_last_event_at', 'attachments']);
        });
    }
};
