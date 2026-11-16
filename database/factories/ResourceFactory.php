<?php

namespace Database\Factories;

use App\Enums\ResourceType;
use App\Models\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Resource>
 */
class ResourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'space_id' => Space::factory(),
            'type' => ResourceType::Desk,
            'name' => 'Recurso '.fake()->unique()->bothify('??-###'),
            'description' => fake()->sentence(),
            'capacity' => 1,
            'hourly_rate' => 3.00,
            'active' => true,
        ];
    }

    public function desk(): static
    {
        return $this->state(fn () => [
            'type' => ResourceType::Desk,
            'capacity' => 1,
            'hourly_rate' => 3.00,
        ]);
    }

    public function room(): static
    {
        return $this->state(fn () => [
            'type' => ResourceType::Room,
            'capacity' => 6,
            'hourly_rate' => 12.00,
        ]);
    }

    public function meetingRoom(): static
    {
        return $this->state(fn () => [
            'type' => ResourceType::MeetingRoom,
            'capacity' => 12,
            'hourly_rate' => 25.00,
        ]);
    }

    public function projector(): static
    {
        return $this->state(fn () => [
            'type' => ResourceType::Projector,
            'capacity' => 1,
            'hourly_rate' => 5.00,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
