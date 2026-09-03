<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who we worked with on a project — the designer, architect, engineer or
 * trade partner — entered on the admin project form. The blog writer credits
 * them (with a link to their site) and the post lists them as the project
 * team. site_* columns cache what their homepage says, so the writer can
 * describe their role in its own words.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('role', 60);
            $table->string('name');
            $table->string('url', 500)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('site_title')->nullable();
            $table->string('site_description', 1000)->nullable();
            $table->text('site_excerpt')->nullable();
            $table->timestamp('site_fetched_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_collaborators');
    }
};
