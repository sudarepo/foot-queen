<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile URLs bind on `username` (/cam/{cam:username}), which had no index —
 * every performer page was a full scan of the cams table. Harmless at a few
 * thousand rows; at 150k+ with crawlers working through every domain's
 * profiles at once, those scans saturated MySQL and slowed every page on
 * every site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cams', function (Blueprint $table) {
            $table->index('username');
        });
    }

    public function down(): void
    {
        Schema::table('cams', function (Blueprint $table) {
            $table->dropIndex(['username']);
        });
    }
};
