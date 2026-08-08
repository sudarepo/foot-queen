<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    /**
     * Never `is_default` by default — the seeded foot-queen site holds that,
     * and creating a site in a test shouldn't quietly demote it (see
     * Site::booted).
     */
    public function definition(): array
    {
        $slug = Str::slug($this->faker->unique()->words(2, true));

        return [
            'slug' => $slug,
            'name' => Str::headline($slug),
            'domains' => ["{$slug}.test"],
            'is_default' => false,
            'is_active' => true,
            'gender' => 'female',
            'tags' => [],
            'seo_pages' => 'foot-queen',
        ];
    }

    /**
     * A site whose slice of the pool is defined by tags, e.g. ['bbw'].
     *
     * @param  array<int, string>  $tags
     */
    public function withTags(array $tags): static
    {
        return $this->state(fn () => ['tags' => $tags]);
    }

    public function onDomain(string $domain): static
    {
        return $this->state(fn () => ['domains' => [$domain]]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
