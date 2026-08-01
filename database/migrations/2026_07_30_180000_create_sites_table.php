<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-tenant foundation: every public domain served by this app is a
     * Site. The request host resolves the current site (ResolveSite
     * middleware); themes, routes, and content scoping all key off it.
     *
     * ss.systems is intentionally NOT a site — it is the central admin host
     * (config/sites.php) and serves the admin UI for all sites.
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();          // 'gsc', 'jpeterson'
            $table->string('name');                    // display name in admin
            $table->string('theme');                   // resources/views/themes/{theme}
            $table->json('hosts');                     // ['gs.construction', 'www.gs.construction']
            $table->string('primary_host');            // canonical host for URL generation
            $table->json('settings')->nullable();      // per-site config (socials, credentials, …)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed the founding tenant here (not a Seeder): the row is
        // infrastructure the app cannot run without, same as the schema.
        DB::table('sites')->insert([
            'slug' => 'gsc',
            'name' => 'GS Construction & Remodeling',
            'theme' => 'gsc',
            'hosts' => json_encode([
                'gs.construction',
                'www.gs.construction',
            ]),
            'primary_host' => 'gs.construction',
            'settings' => json_encode([]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
