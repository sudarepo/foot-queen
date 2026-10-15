<?php

use App\Models\Site;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sites imported from CSV carried category names like "general", "amateur"
 * or "ass" — words, not Chaturbate tags — so their homepage filtered every
 * cam out. Each one moves to its nearest real tag, or to no default category
 * when there isn't one (see `cam-taxonomy.category_aliases`).
 *
 * Per-site featured_categories get the same treatment, dropping entries
 * that map to nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sites')->orderBy('id')->each(function (object $site) {
            $changes = [];

            if ($site->default_category !== null) {
                $category = Site::normalizeCategory($site->default_category);

                if ($category !== $site->default_category) {
                    $changes['default_category'] = $category;
                }
            }

            $featured = json_decode((string) $site->featured_categories, true);

            if (is_array($featured) && $featured !== []) {
                $normalized = collect($featured)
                    ->map(fn (mixed $category) => Site::normalizeCategory((string) $category))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($normalized !== $featured) {
                    $changes['featured_categories'] = $normalized === [] ? null : json_encode($normalized);
                }
            }

            if ($changes !== []) {
                DB::table('sites')->where('id', $site->id)->update($changes);
            }
        });

        Site::flushRegistry();
    }

    /**
     * The original values matched no cams, so there is nothing worth putting
     * back.
     */
    public function down(): void
    {
        //
    }
};
