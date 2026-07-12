<?php

namespace Database\Factories;

use App\Models\Invite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invite>
 */
class InviteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Invite::generateCode(),
            'note' => null,
        ];
    }

    public function used(): static
    {
        return $this->state(fn (array $attributes) => [
            'used_at' => now(),
        ]);
    }
}
