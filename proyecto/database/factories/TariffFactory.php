<?php

namespace Database\Factories;

use App\Enums\TariffKind;
use App\Models\Tariff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tariff>
 */
class TariffFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_id' => null,
            'name' => 'Tarifa base',
            'kind' => TariffKind::Base,
            'price_per_hour' => null,
            'surcharge_percent' => null,
            'starts_at' => null,
            'ends_at' => null,
            'valid_from' => null,
            'valid_until' => null,
            'active' => true,
        ];
    }

    public function afternoon(): static
    {
        return $this->state(fn () => [
            'name' => 'Recargo de tarde',
            'kind' => TariffKind::Afternoon,
            'surcharge_percent' => 15.00,
            'starts_at' => '15:00:00',
            'ends_at' => '20:00:00',
        ]);
    }

    public function weekend(): static
    {
        return $this->state(fn () => [
            'name' => 'Recargo de fin de semana',
            'kind' => TariffKind::Weekend,
            'surcharge_percent' => 20.00,
        ]);
    }
}
