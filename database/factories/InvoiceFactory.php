<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'rectifies_invoice_id' => null,
            'number' => 'FAC-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'amount' => 0,
            'status' => InvoiceStatus::Pending,
            'issued_at' => now(),
            'due_at' => now()->addDays(15)->toDateString(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => InvoiceStatus::Paid]);
    }

    public function void(): static
    {
        return $this->state(fn () => ['status' => InvoiceStatus::Void]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => ['due_at' => now()->subDays(5)->toDateString()]);
    }
}
