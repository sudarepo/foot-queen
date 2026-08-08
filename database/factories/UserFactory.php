<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            /**
             * The default user is the operator's own account — the only kind
             * that existed before site assignments. Use `siteManager()` for
             * the scoped kind.
             */
            'is_admin' => true,
        ];
    }

    /**
     * Someone who can only manage the sites given to them.
     *
     * @param  Site|iterable<int, Site>  $sites
     */
    public function siteManager(Site|iterable $sites): static
    {
        return $this
            ->state(['is_admin' => false])
            ->hasAttached($sites instanceof Site ? [$sites] : $sites, relationship: 'sites');
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
