<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->text('embed_code')->nullable()->after('creative');
            $table->text('destination_url')->nullable()->change();
            $table->string('image_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->dropColumn('embed_code');
        });
    }
};
