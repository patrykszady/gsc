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
        Schema::create('project_testimonial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('testimonial_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'testimonial_id']);
        });

        // Migrate existing project_id data to pivot table
        DB::statement('
            INSERT INTO project_testimonial (project_id, testimonial_id, created_at, updated_at)
            SELECT project_id, id, NOW(), NOW()
            FROM testimonials
            WHERE project_id IS NOT NULL
        ');

        Schema::table('testimonials', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });

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

        // Run testimonial cleanup (merge duplicates, remove generic URLs, fix mojibake in all fields)
        Artisan::call('testimonials:cleanup-duplicates');
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
