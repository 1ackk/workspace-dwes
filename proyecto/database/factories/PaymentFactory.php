<?php

namespace Database\Factories;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'booking_id' => null,
            'provider' => PaymentProvider::Simulated,
            'provider_reference' => fake()->unique()->uuid(),
            'amount' => 10.00,
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
            'payload' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Pending,
            'paid_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Failed,
            'paid_at' => null,
        ]);
    }
}
