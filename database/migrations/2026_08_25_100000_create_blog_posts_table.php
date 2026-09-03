<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            // One post per project (the generator's unit); null for hand-written posts.
            $table->foreignId('project_id')->nullable()->index();
            $table->string('slug');
            $table->string('title');
            $table->string('excerpt', 500)->nullable();
            // Markdown. Media shortcodes ([cover], [before-after], [timelapse],
            // [gallery]) are expanded from the linked project at render time, so
            // an editor rewrites prose without ever touching image plumbing.
            $table->longText('body')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('status', 16)->default('draft')->index(); // draft|published
            $table->string('writer', 16)->nullable();                 // ai|manual
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['site_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
