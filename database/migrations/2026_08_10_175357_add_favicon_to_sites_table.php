<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tab icon per domain.
 *
 * The favicon set in public/ is Foot Queen's, so every other domain has been
 * wearing its icon in the browser tab and in bookmarks. Null keeps that set —
 * the existing site needs no re-upload — and any site that uploads one is
 * served its own file instead (see Site::faviconUrl).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('favicon_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('favicon_path');
        });
    }
};
