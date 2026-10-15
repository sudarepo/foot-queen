<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bearer tokens for the admin MCP server (routes/ai.php).
 *
 * `token` is a SHA-256 of the key, used to look it up on every request.
 * `secret` is the same key encrypted with APP_KEY, so its owner can come back
 * to the AI Assistant page and copy their setup again — see McpToken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('secret')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
    }
};
