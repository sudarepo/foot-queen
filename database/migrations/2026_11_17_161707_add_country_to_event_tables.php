<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The visitor's country (ISO 3166-1 alpha-2) on every analytics event. See
 * App\Services\CountryDetector.
 *
 * Nullable and not backfilled, for the same reason as `device`: older rows
 * have no honest answer (pageviews and cam clicks never stored an IP), and
 * the dashboards say so next to the country filter.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $tables = ['page_view_events', 'cam_click_events', 'ad_impressions', 'ad_clicks'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->char('country', 2)->nullable()->index()->after('site_id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['country']);
                $blueprint->dropColumn('country');
            });
        }
    }
};
