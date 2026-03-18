<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_timelapses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'sort_order']);
        });

        // Add timelapse_id to frames and migrate existing data
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->foreignId('project_timelapse_id')->nullable()->after('id');
        });

        // Migrate existing frames: group by project_id into a timelapse each
        $projectIds = \DB::table('project_timelapse_frames')
            ->distinct()
            ->pluck('project_id');

        foreach ($projectIds as $projectId) {
            $timelapseId = \DB::table('project_timelapses')->insertGetId([
                'project_id' => $projectId,
                'title' => null,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            \DB::table('project_timelapse_frames')
                ->where('project_id', $projectId)
                ->update(['project_timelapse_id' => $timelapseId]);
        }

        // Now make the column non-nullable, add FK, and drop old project_id
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['project_id', 'sort_order']);
            $table->dropColumn('project_id');

            $table->foreignId('project_timelapse_id')
                ->nullable(false)
                ->change();
            $table->foreign('project_timelapse_id')
                ->references('id')
                ->on('project_timelapses')
                ->cascadeOnDelete();

            $table->index(['project_timelapse_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->dropIndex(['project_timelapse_id', 'sort_order']);
            $table->dropForeign(['project_timelapse_id']);
            $table->foreignId('project_id')->nullable()->after('id');
        });

        // Migrate data back
        $timelapses = \DB::table('project_timelapses')->get();
        foreach ($timelapses as $timelapse) {
            \DB::table('project_timelapse_frames')
                ->where('project_timelapse_id', $timelapse->id)
                ->update(['project_id' => $timelapse->project_id]);
        }

        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->dropColumn('project_timelapse_id');
            $table->foreignId('project_id')->nullable(false)->change();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['project_id', 'sort_order']);
        });

        Schema::dropIfExists('project_timelapses');
    }
};
