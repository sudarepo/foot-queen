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
            'categories' => $this->faker->randomElements(['feet', 'lovense', 'latina'], 2),
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
}
