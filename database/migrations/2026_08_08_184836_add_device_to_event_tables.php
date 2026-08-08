<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an analytics event came from a phone or a larger screen.
 *
 * The grid and the feed are not the same experience on a 390px screen as on a
 * 1440px one, so a pooled CTR can hide a variant that wins on one and loses on
 * the other. See App\Services\DeviceDetector.
 *
 * Nullable and backfilled with nothing on purpose: rows logged before this
 * shipped have no honest answer, and guessing one would quietly put fabricated
 * data behind the dashboard's device filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['page_view_events', 'cam_click_events'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('device', 8)->nullable()->index()->after('site_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['page_view_events', 'cam_click_events'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['device']);
                $blueprint->dropColumn('device');
            });
        }
    }
};
