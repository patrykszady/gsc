<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Geo-grid map-pack scans pulled from Local Falcon. One row per scan:
        // a keyword checked across an N×N grid of points around the business,
        // each point reporting our Maps rank there. ARP/ATRP/SoLV are Local
        // Falcon's own aggregates (average rank, average total rank, share of
        // local voice) — stored as reported, raw payload kept for anything
        // the columns don't capture.
        Schema::create('local_falcon_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('falcon_scan_id', 64)->unique();
            $table->string('keyword');
            $table->string('place_id', 128)->nullable();
            $table->unsignedTinyInteger('grid_size')->nullable();   // e.g. 7 for 7×7
            $table->decimal('arp', 5, 2)->nullable();               // average rank in pack
            $table->decimal('atrp', 5, 2)->nullable();              // average total rank
            $table->decimal('solv', 5, 2)->nullable();              // share of local voice %
            $table->timestamp('scanned_at')->nullable()->index();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_falcon_scans');
    }
};
