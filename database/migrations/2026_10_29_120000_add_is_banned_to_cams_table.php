<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cams', function (Blueprint $table) {
            $table->boolean('is_banned')->default(false)->index()->after('is_online');
        });
    }

    public function down(): void
    {
        Schema::table('cams', function (Blueprint $table) {
            $table->dropColumn('is_banned');
        });
    }
};
