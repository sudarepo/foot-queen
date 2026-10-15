<?php

namespace App\Mcp\Tools\Users;

use App\Mcp\Tools\AdminTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Permanently delete a panel user and their MCP tokens. You cannot delete yourself.')]
#[IsDestructive]
class DeleteUser extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['user_id' => ['required', 'integer']]);

        $user = User::query()->find($validated['user_id']);

        if ($user === null) {
            return Response::error("No user with id {$validated['user_id']}.");
        }

        // Same rule as UserPolicy::delete().
        if ($user->is($request->user())) {
            return Response::error('You cannot delete your own account.');
        }

        $user->delete();

        return Response::text("User {$user->email} (id {$user->id}) deleted.");
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->required(),
        ];
    }
}
