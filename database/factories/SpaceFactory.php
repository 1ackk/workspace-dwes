<?php

namespace Database\Factories;

use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Space>
 */
class SpaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'manager_id' => User::factory(),
            'name' => 'Espacio '.fake()->unique()->bothify('??-##'),
            'description' => fake()->sentence(),
            'location' => fake()->streetAddress(),
            'cancellation_notice_hours' => 24,
            'opens_at' => '08:00:00',
            'closes_at' => '20:00:00',
        ];
    }
}
