<?php

use App\Models\Site;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Site tags were stored as typed — "foot fetish cams", "Big Cock" — which
 * Chaturbate's API rejects outright and which never equal a room's own tags.
 * Each moves to the single lowercase word Chaturbate uses (see
 * Site::normalizeTag()).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sites')->orderBy('id')->each(function (object $site) {
            $tags = json_decode((string) $site->tags, true);

            if (! is_array($tags) || $tags === []) {
                return;
            }

            $normalized = Site::normalizeTags($tags);

            if ($normalized !== $tags) {
                DB::table('sites')->where('id', $site->id)->update(['tags' => json_encode($normalized)]);
            }
        });

        Site::flushRegistry();
    }

    /**
     * The original spellings matched nothing, so there is nothing worth
     * putting back.
     */
    public function down(): void
    {
        //
    }
};
