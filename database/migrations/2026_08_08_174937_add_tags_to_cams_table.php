<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The performer's raw provider tags, kept alongside the curated `categories`.
 *
 * `categories` is the intersection of the room's tags with
 * config('cam-taxonomy.featured_categories') — fine for a filter dropdown,
 * useless as a site boundary: a new niche would need its keyword added to the
 * taxonomy *and* a full re-sync before Cam::scopeForSite() could see it. The
 * raw tags make a site's `tags` list work the moment it's saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cams', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('categories');
        });

        /**
         * Backfill so the existing roster doesn't vanish from the listing
         * between this migration and the next sync. `categories` is a subset
         * of the real tags, so a performer pulled in under 'footfetish' alone
         * won't carry 'feet' here and stays hidden until `cams:sync` next
         * runs — minutes, on the existing schedule.
         */
        DB::table('cams')->whereNull('tags')->update([
            'tags' => DB::raw('categories'),
        ]);
    }

    public function down(): void
    {
        Schema::table('cams', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
