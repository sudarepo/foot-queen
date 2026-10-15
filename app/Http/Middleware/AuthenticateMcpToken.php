<?php

namespace App\Http\Middleware;

use App\Models\McpToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an MCP client in on an admin's bearer token (see McpToken).
 *
 * Admin-only by design: every tool on the server is network-wide — any site,
 * any user, the admin flag itself — so there is no scoped version of it to
 * hand a site manager.
 */
class AuthenticateMcpToken
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        $token = filled($bearer) ? McpToken::findValid($bearer) : null;

        if ($token === null || $token->user === null) {
            return response()->json(['error' => 'Invalid or missing MCP token.'], 401);
        }

        if (! $token->user->isAdmin()) {
            return response()->json(['error' => 'The MCP server is only available to administrators.'], 403);
        }

        $token->markAsUsed();

        Auth::setUser($token->user);

        return $next($request);
    }
}
