<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table): void {
            // 'ai' (Gemini-written from the target query + proof projects) or
            // 'template' (the deterministic fallback). Lets the admin list and
            // the autopilot's measurement distinguish the two.
            $table->string('writer', 16)->nullable()->after('template');
        });
    }

    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->dropColumn('writer');
        });
    }
};
