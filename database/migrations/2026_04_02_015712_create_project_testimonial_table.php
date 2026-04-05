<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_testimonial')) {
            Schema::create('project_testimonial', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('testimonial_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['project_id', 'testimonial_id']);
            });
        }

        if (Schema::hasColumn('testimonials', 'project_id')) {
            // Migrate existing project_id data to pivot table.
            // INSERT IGNORE keeps this safe if the migration partially ran before and is retried.
            DB::statement('
                INSERT IGNORE INTO project_testimonial (project_id, testimonial_id, created_at, updated_at)
                SELECT project_id, id, NOW(), NOW()
                FROM testimonials
                WHERE project_id IS NOT NULL
            ');

            Schema::table('testimonials', function (Blueprint $table) {
                // The FK may already be dropped on partially-applied environments.
                try {
                    $table->dropForeign(['project_id']);
                } catch (Throwable) {
                }

                $table->dropColumn('project_id');
            });
        }

        // Fix mojibake-encoded reviewer names (double-encoded UTF-8 curly quotes)
        $mojibake = DB::table('testimonials')
            ->where('reviewer_name', 'LIKE', '%â€%')
            ->orWhereRaw('BINARY `reviewer_name` LIKE ?', ['%'."\xc2\x9d".'%'])
            ->get(['id', 'reviewer_name']);

        foreach ($mojibake as $row) {
            $fixed = str_replace(
                ['â€œ', 'â€™', 'â€', "\xc3\xa2\xe2\x82\xac\xc5\x93", "\xc3\xa2\xe2\x82\xac\xc2\x9d", "\xc2\x9d"],
                ['"', "'", '"', '"', '"', ''],
                $row->reviewer_name
            );
            if ($fixed !== $row->reviewer_name) {
                DB::table('testimonials')->where('id', $row->id)->update(['reviewer_name' => $fixed]);
            }
        }

        // Run cleanup only after review_urls.external_id exists (added in a later migration).
        if (Schema::hasTable('review_urls') && Schema::hasColumn('review_urls', 'external_id')) {
            Artisan::call('testimonials:cleanup-duplicates');
        }
    }

    public function down(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
        });

        // Restore first linked project back to FK
        DB::statement('
            UPDATE testimonials t
            INNER JOIN (
                SELECT testimonial_id, MIN(project_id) AS project_id
                FROM project_testimonial
                GROUP BY testimonial_id
            ) pt ON t.id = pt.testimonial_id
            SET t.project_id = pt.project_id
        ');

        Schema::dropIfExists('project_testimonial');
    }
};
