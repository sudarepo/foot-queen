<?php

namespace App\Console\Commands;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mcp:issue-token
    {email : The administrator the token acts as}
    {--name=MCP client : A label to recognise the token by, e.g. "Claude Code – laptop"}
    {--expires= : Days until the token stops working (default: never)}')]
#[Description('Mint a bearer token for the admin MCP server at /mcp/admin')]
class IssueMcpToken extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        if (! $user->isAdmin()) {
            $this->error('The MCP server is admin-only; this user is not an administrator.');

            return self::FAILURE;
        }

        $days = $this->option('expires');

        if ($days !== null && (! ctype_digit((string) $days) || (int) $days < 1)) {
            $this->error('--expires must be a whole number of days.');

            return self::FAILURE;
        }

        [$token, $plaintext] = McpToken::issue(
            $user,
            (string) $this->option('name'),
            $days !== null ? now()->addDays((int) $days) : null,
        );

        $this->info("Token #{$token->id} issued for {$user->email}".($token->expires_at ? ", expiring {$token->expires_at->toDateString()}" : '').'.');
        $this->newLine();
        $this->line($plaintext);
        $this->newLine();
        $this->warn('Copy it now — it is stored hashed and cannot be shown again.');
        $this->line('Server URL: '.url('/mcp/admin'));

        return self::SUCCESS;
    }
}
