<?php

use App\Services\CamTagIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each cam's tags as indexed rows (see App\Services\CamTagIndex), so
 * Cam::scopeForSite is an index lookup instead of a JSON_CONTAINS per tag.
 *
 * Replaces a site ↔ cam table whose backfill never finished in production —
 * it was heading for ~25M rows — so that table is dropped if a killed run
 * left it behind.
 *
 * Filled here rather than left to the next sync: on a zero-downtime deploy
 * migrations run before the release goes live, so the new scope never sees
 * an empty table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cam_site');

        Schema::create('cam_tags', function (Blueprint $table) {
            $table->foreignId('cam_id')->constrained()->cascadeOnDelete();
            $table->string('tag', CamTagIndex::MAX_TAG_LENGTH);

            $table->primary(['cam_id', 'tag']);
            $table->index(['tag', 'cam_id']);
        });

        app(CamTagIndex::class)->rebuild();
    }

    public function down(): void
    {
        Schema::dropIfExists('cam_tags');
    }
};
