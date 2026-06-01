<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->timestamp('hive_sent_at')->nullable()->after('utm_campaign');
            $table->unsignedBigInteger('hive_lead_id')->nullable()->after('hive_sent_at');
            $table->string('hive_send_error', 500)->nullable()->after('hive_lead_id');
            $table->index('hive_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropIndex(['hive_sent_at']);
            $table->dropColumn(['hive_sent_at', 'hive_lead_id', 'hive_send_error']);
        });
    }
};
