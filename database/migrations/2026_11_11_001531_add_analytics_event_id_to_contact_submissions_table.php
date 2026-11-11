<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The analytics row (tracked_events.id) a lead was made from. "Make lead"
     * on the hub's Analytics screen turns a Call, Text or Email tap into a
     * lead once; asking again finds the lead already made by this column.
     */
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->string('analytics_event_id', 64)->nullable()->index()->after('extracted');
        });
    }

    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropIndex(['analytics_event_id']);
            $table->dropColumn('analytics_event_id');
        });
    }
};
