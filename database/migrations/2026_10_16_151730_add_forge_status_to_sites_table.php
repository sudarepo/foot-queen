<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each site stands in Forge, recorded on the row instead of read live
 * from the Forge API on every admin page load. Kept current by the
 * provisioning job a new site (or a domain change) triggers, and by a weekly
 * check (sites:check-forge-status) for anything changed in Forge directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('forge_domain_status', 16)->default('unknown')->after('domains');
            $table->string('forge_ssl_status', 16)->default('unknown')->after('forge_domain_status');
            $table->string('forge_error', 500)->nullable()->after('forge_ssl_status');
            $table->timestamp('forge_checked_at')->nullable()->after('forge_error');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['forge_domain_status', 'forge_ssl_status', 'forge_error', 'forge_checked_at']);
        });
    }
};
