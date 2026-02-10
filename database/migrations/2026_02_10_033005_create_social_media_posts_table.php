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
        Schema::create('social_media_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_image_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // 'instagram', 'facebook'
            $table->string('status')->default('pending'); // pending, published, failed
            $table->text('caption')->nullable();
            $table->text('hashtags')->nullable();
            $table->string('link_url')->nullable();
            $table->string('platform_post_id')->nullable(); // IG/FB media ID
            $table->string('platform_permalink')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamps();

            $table->index(['platform', 'status']);
            $table->unique(['project_image_id', 'platform']); // Never re-post same image to same platform
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_media_posts');
    }
};
