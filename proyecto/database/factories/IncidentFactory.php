<?php

namespace Database\Factories;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'resource_id' => Resource::factory(),
            'booking_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => IncidentStatus::Open,
            'attachments' => null,
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => IncidentStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }
}
