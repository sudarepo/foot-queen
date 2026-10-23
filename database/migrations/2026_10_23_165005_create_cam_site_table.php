<?php

use App\Services\SiteCamIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precomputed site ↔ cam tag matches (see App\Services\SiteCamIndex), so
 * Cam::scopeForSite is an indexed join instead of a JSON_CONTAINS per tag.
 *
 * Filled here rather than left to the next sync: on a zero-downtime deploy
 * migrations run before the release goes live, so the new scope never sees
 * an empty table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cam_site', function (Blueprint $table) {
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cam_id')->constrained()->cascadeOnDelete();

            $table->primary(['site_id', 'cam_id']);
            $table->index('cam_id');
        });

        app(SiteCamIndex::class)->rebuild();
    }

    public function down(): void
    {
        Schema::dropIfExists('cam_site');
    }
};
