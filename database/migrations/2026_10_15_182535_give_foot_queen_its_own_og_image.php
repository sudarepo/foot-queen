<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * public/og-image.png is now the generic sharing image every site falls back
 * to, so Foot Queen's artwork moves to an upload of its own. A site that has
 * already uploaded one is left alone.
 */
return new class extends Migration
{
    private const PATH = 'sites/og/foot-queen.png';

    public function up(): void
    {
        $site = DB::table('sites')->where('slug', 'foot-queen')->first();

        if ($site === null || filled($site->og_image_path)) {
            return;
        }

        Storage::disk('public')->put(self::PATH, file_get_contents(public_path('img/foot-queen-og.png')));

        DB::table('sites')->where('id', $site->id)->update(['og_image_path' => self::PATH]);
    }

    public function down(): void
    {
        DB::table('sites')
            ->where('slug', 'foot-queen')
            ->where('og_image_path', self::PATH)
            ->update(['og_image_path' => null]);

        Storage::disk('public')->delete(self::PATH);
    }
};
