<?php

namespace App\Console\Commands;

use App\Models\McpToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mcp:revoke-token {id : Token id from mcp:list-tokens}')]
#[Description('Revoke an admin MCP server token')]
class RevokeMcpToken extends Command
{
    public function handle(): int
    {
        $token = McpToken::query()->find($this->argument('id'));

        if ($token === null) {
            $this->error("No MCP token with id {$this->argument('id')}.");

            return self::FAILURE;
        }

        $token->delete();

        $this->info("Token #{$token->id} ({$token->name}) revoked.");

        return self::SUCCESS;
    }
}
