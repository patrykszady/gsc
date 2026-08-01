<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The other two tenants.
     *
     * Both start inactive on purpose:
     *   - Site::forHost() only matches active sites, so until each theme is
     *     built these hosts do NOT resolve to a tenant. NoIndexNonProduction
     *     then treats them as unowned and sends noindex, which is exactly what
     *     we want for a domain parked ahead of its design.
     *   - Flip is_active to true when the theme is ready to be served.
     *
     * ss.systems is a normal tenant here (public marketing site). Its role as
     * the admin hub is separate, driven by config('sites.admin_hosts').
     */
    public function up(): void
    {
        $rows = [
            [
                'slug' => 'ss',
                'name' => 'SS Systems',
                'theme' => 'ss',
                // dev.ss.systems: Cloudflare-Tunnel preview of this tenant from
                // the dev machine. The dev. prefix keeps it noindexed.
                'hosts' => json_encode(['ss.systems', 'www.ss.systems', 'dev.ss.systems']),
                'primary_host' => 'ss.systems',
                // Active from day one: its theme ships with this migration, and
                // activating is what makes ss.systems resolve → indexable.
                'active' => true,
            ],
            [
                'slug' => 'jpeterson',
                'name' => 'J. Peterson Design',
                'theme' => 'jpeterson',
                'hosts' => json_encode(['jpeterson-design.com', 'www.jpeterson-design.com']),
                'primary_host' => 'jpeterson-design.com',
            ],
        ];

        foreach ($rows as $row) {
            if (DB::table('sites')->where('slug', $row['slug'])->exists()) {
                continue;
            }

            $active = (bool) ($row['active'] ?? false);
            unset($row['active']);

            DB::table('sites')->insert($row + [
                'settings' => json_encode([]),
                'is_active' => $active,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('sites')->whereIn('slug', ['ss', 'jpeterson'])->delete();
    }
};
