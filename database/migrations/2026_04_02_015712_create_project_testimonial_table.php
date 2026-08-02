<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            // Migrate existing project_id data to the pivot. insertOrIgnore()
            // keeps this safe if the migration partially ran before and is
            // retried — and unlike the raw `INSERT IGNORE ... NOW()` it used to
            // be, it compiles on every driver. The MySQL-only SQL made THIS
            // migration abort the whole chain under phpunit's :memory: SQLite,
            // which is why the test suite could not run at all.
            $now = now();
            $rows = DB::table('testimonials')
                ->whereNotNull('project_id')
                ->get(['id', 'project_id'])
                ->map(fn ($t) => [
                    'project_id' => $t->project_id,
                    'testimonial_id' => $t->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            if ($rows !== []) {
                DB::table('project_testimonial')->insertOrIgnore($rows);
            }

            Schema::table('testimonials', function (Blueprint $table) {
                // The FK may already be dropped on partially-applied environments.
                try {
                    $table->dropForeign(['project_id']);
                } catch (\Throwable) {
                }

                $table->dropColumn('project_id');
            });
        }

        // Fix mojibake-encoded reviewer names (double-encoded UTF-8 curly quotes).
        // The BINARY comparison is MySQL syntax; other drivers get the plain
        // LIKE, which is sufficient there (SQLite LIKE is byte-oriented).
        $mojibake = DB::table('testimonials')
            ->where('reviewer_name', 'LIKE', '%â€%')
            ->when(
                in_array(DB::getDriverName(), ['mysql', 'mariadb'], true),
                fn ($q) => $q->orWhereRaw('BINARY `reviewer_name` LIKE ?', ['%'."\xc2\x9d".'%']),
                fn ($q) => $q->orWhere('reviewer_name', 'LIKE', '%'."\xc2\x9d".'%'),
            )
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

        // Restore first linked project back to FK. Correlated subquery, not
        // MySQL's UPDATE...INNER JOIN, so a rollback also works off-MySQL.
        DB::statement('
            UPDATE testimonials
            SET project_id = (
                SELECT MIN(project_id)
                FROM project_testimonial
                WHERE project_testimonial.testimonial_id = testimonials.id
            )
        ');

        Schema::dropIfExists('project_testimonial');
    }
};
