<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copy written for one service in one town — a Western Springs kitchen page
 * and a Western Springs bathroom page used to share 70-80% of their prose,
 * because both rendered the town's whole local block. Now each (town,
 * service) pair has its own intro, requests, permit notes and FAQ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_service_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_served_id')->constrained('areas_served')->cascadeOnDelete();
            $table->string('service', 40);
            $table->text('intro');
            $table->text('popular_requests')->nullable();
            $table->text('permit_notes')->nullable();
            $table->json('faq')->nullable();
            $table->string('model', 80)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['area_served_id', 'service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_service_contents');
    }
};
