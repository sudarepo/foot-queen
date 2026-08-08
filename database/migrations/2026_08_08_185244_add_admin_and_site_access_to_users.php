<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of panel user, now that one deploy serves several domains.
 *
 * An admin sees everything and is the only one who can add users or create
 * sites. Everyone else is scoped: they can edit the sites listed for them in
 * `site_user` and nothing else — the way to hand a partner their own domain's
 * branding and copy without handing them the rest of the network.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        /**
         * Everyone who already had a login predates the distinction and got in
         * through `make:filament-user` — i.e. whoever runs this deploy. They
         * keep the full access they have today; locking them out of their own
         * panel on deploy would be the alternative.
         */
        DB::table('users')->update(['is_admin' => true]);

        Schema::create('site_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->primary(['user_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
