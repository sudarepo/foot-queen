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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Update a panel user: name, email, password, admin flag or site assignments. Only the fields you pass change.')]
#[IsIdempotent]
class UpdateUser extends AdminTool
{
    public function handle(Request $request): Response
    {
        $request->validate(['user_id' => ['required', 'integer']]);

        $user = User::query()->find($request->get('user_id'));

        if ($user === null) {
            return Response::error("No user with id {$request->get('user_id')}.");
        }

        $validated = Arr::except($request->validate([
            'user_id' => ['required', 'integer'],
            ...UserFields::rules($user),
        ]), ['user_id']);

        /**
         * The token making this call belongs to the acting admin, and only
         * works while they're an admin — demoting yourself would cut this
         * session off mid-way, and if you were the last admin, leave no one
         * able to promote anyone back.
         */
        if ($user->is($request->user()) && array_key_exists('is_admin', $validated) && ! $validated['is_admin']) {
            return Response::error('You cannot remove your own admin flag. Ask another administrator to do it.');
        }

        $user->update(Arr::except($validated, ['site_ids']));

        if (array_key_exists('site_ids', $validated)) {
            $user->sites()->sync($validated['site_ids']);
        }

        return Response::json(UserFields::present($user->refresh()));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->required(),
            ...UserFields::schema($schema),
        ];
    }
}
