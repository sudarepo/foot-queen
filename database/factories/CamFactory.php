<?php

namespace Database\Factories;

use App\Models\Cam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cam>
 */
class CamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'chaturbate',
            'external_id' => $this->faker->unique()->uuid(),
            'username' => $this->faker->userName(),
            'gender' => 'female',
            'age' => $this->faker->numberBetween(18, 45),
            'hair_color' => $this->faker->randomElement(['blonde', 'brunette', 'black', 'red']),
            'body_type' => $this->faker->randomElement(['slim', 'athletic', 'average', 'curvy']),
            // 'feet' is always present: the sync is tag-scoped to foot cams, so
            // that's what the table actually holds.
            'categories' => ['feet', $this->faker->randomElement(['lovense', 'latina'])],
            'viewers' => $this->faker->numberBetween(1, 5000),
            'thumbnail_url' => $this->faker->imageUrl(),
            'room_url' => 'https://chaturbate.com/'.$this->faker->userName().'/',
            'embed_url' => null,
            'room_subject' => $this->faker->sentence(),
            'country' => $this->faker->countryCode(),
            'is_hd' => $this->faker->boolean(),
            'is_new' => $this->faker->boolean(),
            'is_online' => true,
            'last_seen_at' => now(),
        ];
    }

    /**
     * Mirror `categories` into the raw `tags` unless a test sets tags itself.
     *
     * Cam::scopeForSite() filters on `tags`, so without this every factory cam
     * would fall outside every site. Running after the state is applied means
     * a test overriding `categories` gets matching tags for free — which is
     * how the real data looks anyway, `categories` being the curated subset
     * of the tags the provider returned.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Cam $cam) {
            $cam->tags ??= $cam->categories;
        });
    }
}
