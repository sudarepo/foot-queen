<?php

namespace App\Mcp\Support;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;

/**
 * The editable fields of a panel user, shared by create-user and update-user.
 * Mirrors UserForm.
 */
class UserFields
{
    /**
     * @return array<string, Type>
     */
    public static function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Max 255 chars.'),
            'email' => $schema->string()->description('Login email, unique.'),
            'password' => $schema->string()->description('New password, min 8 chars. Omit to leave unchanged.'),
            'is_admin' => $schema->boolean()->description('Administrator: every site, plus managing sites and users. Non-admins only reach the sites in site_ids.'),
            'site_ids' => $schema->array()->items($schema->integer())->description('Ids of the sites a non-admin may manage. Replaces the current assignments.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(?User $user = null): array
    {
        $creating = $user === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => [$creating ? 'required' : 'sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->getKey())],
            'password' => [$creating ? 'required' : 'sometimes', 'string', 'min:8', 'max:255'],
            'is_admin' => ['sometimes', 'boolean'],
            'site_ids' => ['sometimes', 'array'],
            'site_ids.*' => ['integer', Rule::exists('sites', 'id')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->isAdmin(),
            'sites' => $user->sites()->get(['sites.id', 'slug', 'name'])->map->only(['id', 'slug', 'name'])->all(),
            'mcp_tokens' => $user->mcpTokens()->get()->map(fn ($token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ])->all(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
