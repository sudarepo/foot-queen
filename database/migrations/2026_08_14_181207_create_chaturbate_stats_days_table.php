<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chaturbate_stats_days', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('program', 191);
            // Null (not 0) means the API reported no figure for this program
            // on this date — Chaturbate sends an empty string for that,
            // which is a different thing from a real $0.00 day.
            $table->decimal('payout', 12, 4)->nullable();
            // Settlement rows (payout requests, adjustments, cashed-out
            // tokens) aren't earned revenue — one already carries a negative
            // payout (a withdrawal). Flagged here so a "total revenue" query
            // can exclude them without string-matching the program name.
            $table->boolean('is_ledger')->default(false);
            // The full row as column_name => value, so per-program columns
            // (Raw Hits, Engaged Hits, Free Registrations, ...) stay
            // available without a schema change per program.
            $table->json('data');
            $table->timestamps();

            $table->unique(['date', 'program']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chaturbate_stats_days');
    }
};
