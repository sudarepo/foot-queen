<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The encrypted copy of each MCP key (see McpToken), which lets its owner
 * reopen the AI Assistant page and copy their setup again.
 *
 * create_mcp_tokens_table was amended to include this column after it had
 * already run in production, so it's added here for databases that ran the
 * first version — and skipped for any that got it from the amended one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mcp_tokens', 'secret')) {
            return;
        }

        Schema::table('mcp_tokens', function (Blueprint $table) {
            $table->text('secret')->nullable()->after('token');
        });
    }

    /**
     * Left in place: the column may have come from create_mcp_tokens_table,
     * whose own rollback drops the whole table.
     */
    public function down(): void
    {
        //
    }
};
