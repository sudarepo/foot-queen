<?php

use App\Services\HomepageLayout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site, per-device control over what "/" serves: the grid-vs-feed A/B
 * split, or one layout outright.
 *
 * The split is a means of deciding, not a permanent state — a site that has
 * decided should be able to serve the winner to everyone instead of sending
 * half its traffic to the layout it already knows is worse. Split by device
 * because the two layouts aren't equally good on both: the feed is designed
 * for a phone, and a site can reasonably settle on it there while still
 * testing on desktop.
 *
 * Both default to 'ab', so every existing site keeps splitting exactly as it
 * did before this shipped. See App\Services\HomepageLayout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('home_layout_desktop', 8)->default(HomepageLayout::AbTest->value)->after('feed_meta');
            $table->string('home_layout_mobile', 8)->default(HomepageLayout::AbTest->value)->after('home_layout_desktop');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['home_layout_desktop', 'home_layout_mobile']);
        });
    }
};
