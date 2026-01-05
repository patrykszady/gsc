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
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('reviewer_name');
            $table->string('project_location')->nullable();
            $table->string('project_type')->nullable(); // kitchens, bathrooms, basements, home-remodels, etc.
            $table->text('review_description');
            $table->date('review_date')->nullable();
            $table->string('review_url')->nullable();
            $table->string('review_image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
