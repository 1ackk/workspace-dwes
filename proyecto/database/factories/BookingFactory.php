<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'invoice_id' => null,
            'date' => now()->addDay()->toDateString(),
            'status' => BookingStatus::PendingPayment,
            'expires_at' => now()->addMinutes(15),
            'cancelled_at' => null,
            'total' => 0,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Confirmed,
            'expires_at' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Cancelled,
            'expires_at' => null,
            'cancelled_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Expired,
            'expires_at' => now()->subMinutes(30),
        ]);
    }

    /**
     * Añade un recurso con su franja y calcula el precio (tarifa base × horas)
     * y el total. Se puede encadenar para reservas multirrecurso.
     */
    public function withSlot(Resource $resource, CarbonInterface $start, CarbonInterface $end): static
    {
        return $this->afterCreating(function (Booking $booking) use ($resource, $start, $end): void {
            $hours = $start->diffInMinutes($end) / 60;
            $price = round($hours * (float) $resource->hourly_rate, 2);

            $booking->resources()->attach($resource->id, [
                'start_at' => $start,
                'end_at' => $end,
                'precio_final' => $price,
            ]);

            $booking->update(['total' => (float) $booking->total + $price]);
        });
    }
}
