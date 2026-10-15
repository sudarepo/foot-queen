<?php

namespace App\Console\Commands;

use App\Models\McpToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mcp:list-tokens')]
#[Description('List admin MCP server tokens (without their secrets)')]
class ListMcpTokens extends Command
{
    public function handle(): int
    {
        $tokens = McpToken::query()->with('user')->orderBy('id')->get();

        if ($tokens->isEmpty()) {
            $this->info('No MCP tokens issued.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'User', 'Admin', 'Last used', 'Expires'],
            $tokens->map(fn (McpToken $token): array => [
                $token->id,
                $token->name,
                $token->user?->email,
                $token->user?->isAdmin() ? 'yes' : 'no (inactive)',
                $token->last_used_at?->diffForHumans() ?? 'never',
                $token->expires_at?->toDateString() ?? 'never',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
