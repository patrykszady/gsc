<?php

use App\Models\BlogPost;
use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The date a post is shown with. It is derived from the project's completion
 * month (a seeded day inside it), not from when the AI wrote the draft or when
 * an admin clicked publish — so a story about a 2023 kitchen reads as a 2023
 * story even though the blog launched in 2026.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->date('dated_at')->nullable()->after('published_at');
        });

        foreach (BlogPost::withoutGlobalScopes()->cursor() as $post) {
            $project = Project::withoutGlobalScopes()->find($post->project_id);
            if ($project) {
                $post->forceFill(['dated_at' => BlogPost::dateFor($project)])->saveQuietly();
            }
        }
    }

    public function down(): void
    {
        Schema::table('blog_posts', fn (Blueprint $table) => $table->dropColumn('dated_at'));
    }
};
