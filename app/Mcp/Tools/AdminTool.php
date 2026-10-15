<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

/**
 * Every tool on the admin server.
 *
 * The route middleware already turns non-admins away; this is the same rule
 * applied a second time where the tool is listed and invoked, so a tool can
 * never be reached through some other server or route it gets registered on.
 */
abstract class AdminTool extends Tool
{
    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->isAdmin();
    }
}
