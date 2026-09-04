<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business profiles and directory listings the citation builder creates and
 * keeps: one row per directory, its status, the listing URL once it exists,
 * the account used, what a human still has to do, and the run log with
 * screenshots. The weekly link check updates links_to_us.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('slug', 60);
            $table->string('name', 120);
            $table->unsignedTinyInteger('tier')->default(2);
            $table->string('mechanism', 20)->default('form');
            $table->string('homepage', 255)->nullable();
            $table->string('start_url', 500)->nullable();
            $table->string('listing_url', 500)->nullable();
            $table->string('status', 30)->default('planned');
            $table->string('account_email', 191)->nullable();
            $table->text('account_password')->nullable();
            $table->unsignedSmallInteger('photos_uploaded')->default(0);
            $table->boolean('links_to_us')->nullable();
            $table->boolean('nofollow')->nullable();
            $table->text('human_reason')->nullable();
            $table->text('note')->nullable();
            $table->json('log')->nullable();
            $table->json('screenshots')->nullable();
            $table->json('verification')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('live_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citations');
    }
};
