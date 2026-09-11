<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * Seeds the services table from the Project::projectTypes() list it
 * replaced — the seven slugs verbatim, because existing projects store
 * them in project_type, with the same labels the project form showed.
 *
 * firstOrCreate, keyed by slug: safe to re-run, never clobbers a rename
 * made in admin.
 */
class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['slug' => 'kitchen', 'name' => 'Kitchen Remodel', 'sort_order' => 1],
            ['slug' => 'bathroom', 'name' => 'Bathroom Remodel', 'sort_order' => 2],
            ['slug' => 'basement', 'name' => 'Basement Finish', 'sort_order' => 3],
            ['slug' => 'addition', 'name' => 'Home Addition', 'sort_order' => 4],
            ['slug' => 'home-remodel', 'name' => 'Home Remodel', 'sort_order' => 5],
            ['slug' => 'mudroom', 'name' => 'Mudroom / Laundry', 'sort_order' => 6],
            ['slug' => 'exterior', 'name' => 'Exterior/Siding', 'sort_order' => 7],
        ];

        foreach ($services as $service) {
            Service::firstOrCreate(['slug' => $service['slug']], $service);
        }
    }
}
