<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only: state/zip/address_candidates fill in from
 * App\Services\LeadAddressCompleter (ported from hive2025) when a lead's
 * address arrives incomplete. No existing column is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->string('state', 2)->nullable()->after('city');
            $table->string('zip', 10)->nullable()->after('state');
            // Populated only when a bare street matched more than one real
            // address near the office — see LeadAddressCompleter — so a human
            // can pick rather than the system guessing.
            $table->json('address_candidates')->nullable()->after('zip');
        });
    }

    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropColumn(['state', 'zip', 'address_candidates']);
        });
    }
};
