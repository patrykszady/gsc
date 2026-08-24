<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Geo-grid map-pack visibility from Local Falcon — the channel that
        // takes the clicks on our commercial SERPs had zero instrumentation.
        Schema::create('local_falcon_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('scan_id', 64)->unique();
            $table->string('keyword');
            $table->dateTime('scanned_at')->nullable();
            $table->decimal('arp', 6, 2)->nullable();   // average rank across grid points
            $table->decimal('atrp', 6, 2)->nullable();  // avg total rank position
            $table->decimal('solv', 6, 2)->nullable();  // share of local voice %
            $table->unsignedSmallInteger('grid_points')->nullable();
            $table->unsignedSmallInteger('in_top3')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index(['keyword', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_falcon_scans');
    }
};
