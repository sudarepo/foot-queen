<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\Servers\AdminServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * Admin MCP server — lets an AI client (Claude, ChatGPT, Codex…) read and
 * manage the whole network on an administrator's behalf.
 *
 * Authenticated by a bearer token minted with `php artisan mcp:issue-token`;
 * see MCP.md for connecting clients. Not in the `web` group: there's no
 * session or CSRF here, and ResolveSite has nothing to do — the server is the
 * same on every domain that reaches it.
 */
Mcp::web('/mcp/admin', AdminServer::class)
    ->middleware([AuthenticateMcpToken::class, 'throttle:120,1']);
