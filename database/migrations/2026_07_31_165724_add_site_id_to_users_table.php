<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope an admin user to one tenant.
 *
 * Until now the only guard on /admin/{site}/… was `auth`: any authenticated
 * user could switch to any tenant and read its projects, leads and contact
 * submissions. Fine while the only account was the platform owner's,
 * unacceptable the moment a client gets a login.
 *
 * NULL = platform admin, sees and administers every site. That keeps the
 * existing owner account working with no data change, and makes the
 * restricted case the one you have to opt into.
 *
 * Deliberately NOT a role column or a pivot: today the rule is "this person
 * belongs to this one business". A pivot can replace it when someone genuinely
 * needs two tenants; guessing at that now would be inventing a permissions
 * system nobody has asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('site_id')
                ->nullable()
                ->after('id')
                ->constrained('sites')
                // NOT nullOnDelete(): deleting a tenant would silently promote
                // its client login to a platform admin with access to every
                // other tenant.
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
