<?php

namespace Database\Factories;

use App\Models\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'slug' => fake()->randomElement(['about', 'links']),
            'body' => fake()->paragraphs(2, true),
        ];
    }

    /**
     * The About page.
     */
    public function about(): static
    {
        return $this->state(fn (array $attributes) => ['slug' => 'about']);
    }

    /**
     * The Links page.
     */
    public function links(): static
    {
        return $this->state(fn (array $attributes) => ['slug' => 'links']);
    }
}
