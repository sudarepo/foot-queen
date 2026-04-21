<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cams', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 32);          // 'chaturbate', 'stripchat', ...
            $table->string('external_id', 128);      // unique within provider
            $table->string('username', 128);

            $table->string('gender', 16)->index();   // female|male|trans|couple
            $table->unsignedTinyInteger('age')->nullable()->index();
            $table->string('hair_color', 16)->nullable()->index();
            $table->string('body_type', 16)->nullable()->index();

            $table->json('categories')->nullable();

            $table->unsignedInteger('viewers')->default(0)->index();

            $table->text('thumbnail_url')->nullable();
            $table->text('room_url');

            $table->string('room_subject', 200)->nullable();
            $table->string('country', 2)->nullable()->index();
            $table->string('spoken_languages', 128)->nullable();
            $table->boolean('is_hd')->default(false)->index();
            $table->boolean('is_new')->default(false)->index();

            $table->boolean('is_online')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'external_id']);

            // Composite index supports the most common filter + sort combination.
            $table->index(['is_online', 'gender', 'viewers']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cams');
    }
};
