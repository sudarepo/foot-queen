<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site overrides for the four legal pages (2257, privacy, terms, DMCA).
 *
 * The pages themselves need no columns to exist: every site serves all four
 * out of the shared default text in resources/views/legal/defaults, with the
 * site's own name, domain and contact address filled in (see
 * LegalPageResolver). This column only holds the parts an admin has chosen to
 * write differently — null, the state every existing site starts in, means
 * "use the standard text", and keeps improvements to it flowing through.
 *
 * Shape: { "<LegalPage value>": { "title": ?string, "body": ?string } }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('legal_pages')->nullable()->after('seo_pages');

            /**
             * The address the legal pages tell visitors to write to — DMCA
             * notices, privacy requests, age-content complaints. Null derives
             * one from the site's first domain rather than leaving the pages
             * with no route of contact at all, which is what makes a DMCA
             * policy legally useless.
             */
            $table->string('legal_contact_email', 190)->nullable()->after('legal_pages');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['legal_pages', 'legal_contact_email']);
        });
    }
};
