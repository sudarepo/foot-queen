<?php

namespace App\Mcp\Tools\Users;

use App\Mcp\Support\UserFields;
use App\Mcp\Tools\AdminTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List panel users with their admin flag, assigned sites and MCP tokens.')]
#[IsReadOnly]
class ListUsers extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:255']]);

        $search = $validated['search'] ?? null;

        $users = User::query()
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->get();

        return Response::json($users->map(UserFields::present(...))->all());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Match against name or email.'),
        ];
    }
}
