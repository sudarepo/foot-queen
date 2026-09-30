<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('placement', 64)->index();
            $table->string('advertiser', 120);
            $table->string('campaign', 120);
            $table->string('creative', 120);
            $table->text('destination_url');
            $table->string('image_path');
            $table->string('alt_text', 160)->nullable();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('weight')->default(1);
            $table->timestamps();

            $table->index(['site_id', 'placement', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads');
    }
};
