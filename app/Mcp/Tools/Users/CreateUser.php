<?php

namespace App\Mcp\Tools\Users;

use App\Mcp\Support\UserFields;
use App\Mcp\Tools\AdminTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a panel user — an administrator, or someone who manages only the sites in site_ids.')]
class CreateUser extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(UserFields::rules());

        $user = User::query()->create([
            'is_admin' => false,
            ...Arr::except($validated, ['site_ids']),
        ]);

        $user->sites()->sync($validated['site_ids'] ?? []);

        return Response::json(UserFields::present($user));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $fields = UserFields::schema($schema);

        foreach (['name', 'email', 'password'] as $required) {
            $fields[$required]->required();
        }

        return $fields;
    }
}
