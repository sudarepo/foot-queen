<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which domain an analytics event happened on.
 *
 * Without this the grid-vs-feed A/B test silently pools every site's traffic
 * into one sample, and a CTR comparison stops describing any actual page.
 *
 * Nullable rather than required: the redirect and page-view logging paths run
 * on every request, and losing an event to a constraint violation is worse
 * than losing its site attribution.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['page_view_events', 'cam_click_events'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('site_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });

            // Everything logged so far happened on the one site that existed.
            DB::table($table)->update([
                'site_id' => DB::table('sites')->where('slug', 'foot-queen')->value('id'),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['page_view_events', 'cam_click_events'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('site_id');
            });
        }
    }
};
