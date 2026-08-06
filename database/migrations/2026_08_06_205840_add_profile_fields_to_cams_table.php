<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Performer profile data — the "Bio" and "Pics & Vids" tabs of a
     * Chaturbate room — fetched separately from the online-rooms feed
     * (one request per performer) and rendered on our own profile page.
     *
     * Kept out of the main sync's column list on purpose: CamSyncService
     * upserts every five minutes and must not blank these out.
     */
    public function up(): void
    {
        Schema::table('cams', function (Blueprint $table) {
            // Plain text, already stripped of the affiliate links, tracker
            // images, and hidden markup performers pack their bios with.
            // See BioSanitizer.
            $table->text('bio')->nullable();

            // Scalar profile fields (location, languages, follower count, …).
            // JSON rather than a column each: display-only, never filtered on.
            $table->json('profile_attributes')->nullable();

            // The "Pics & Vids" tab: cover image, title, token price, and
            // whether each set is a video or a photo set.
            $table->json('photo_sets')->nullable();

            // Null means "never fetched" — which is what triggers the inline
            // fetch on first profile-page view. Indexed because the backfill
            // command orders by it to find the stalest rows.
            $table->timestamp('profile_fetched_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('cams', function (Blueprint $table) {
            $table->dropColumn(['bio', 'profile_attributes', 'photo_sets', 'profile_fetched_at']);
        });
    }
};
