<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow a contact submission with no phone number.
 *
 * The column was NOT NULL because the website form requires a phone. Leads
 * mirrored from the crew@ inbox have no such guarantee — the enquiry that
 * prompted this feature ("Basement gaming, theater and lounge renovation —
 * Gurnee") gives a name, an address and a full scope, and no phone at all.
 *
 * The form still requires one; that rule lives in the form request, which is
 * where it belongs. Storing '' or a fake number to satisfy a column
 * constraint would put invented data in front of whoever works the lead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->string('phone', 20)->nullable(false)->change();
        });
    }
};
