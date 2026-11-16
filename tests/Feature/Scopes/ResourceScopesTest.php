<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\Space;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');

    $this->resource = Resource::factory()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function occupy(Resource $resource, string $start = '2026-12-10 10:00', string $end = '2026-12-10 12:00', array $attributes = []): Booking
{
    return Booking::factory()->confirmed()
        ->withSlot($resource, Carbon::parse($start), Carbon::parse($end))
        ->create($attributes);
}

function isAvailable(Resource $resource, string $start = '2026-12-10 10:00', string $end = '2026-12-10 12:00', ?int $ignoreBookingId = null): bool
{
    return Resource::query()
        ->whereKey($resource->id)
        ->available(Carbon::parse($start), Carbon::parse($end), $ignoreBookingId)
        ->exists();
}

describe('active', function () {
    test('returns only the active resources', function () {
        $retired = Resource::factory()->create(['active' => false]);

        expect(Resource::query()->active()->pluck('id')->all())
            ->toContain($this->resource->id)
            ->not->toContain($retired->id);
    });
});

describe('forSpace', function () {
    test('returns only the resources of the space', function () {
        $other = Resource::factory()->create();

        expect(Resource::query()->forSpace($this->resource->space_id)->pluck('id')->all())
            ->toBe([$this->resource->id])
            ->not->toContain($other->id);
    });

    test('returns nothing for a space without resources', function () {
        expect(Resource::query()->forSpace(Space::factory()->create()->id)->exists())->toBeFalse();
    });
});

describe('available', function () {
    test('a resource without bookings is available', function () {
        expect(isAvailable($this->resource))->toBeTrue();
    });

    test('compares the requested slot with the booked one', function (string $start, string $end, bool $expected) {
        occupy($this->resource);

        expect(isAvailable($this->resource, $start, $end))->toBe($expected);
    })->with([
        'la misma franja' => ['2026-12-10 10:00', '2026-12-10 12:00', false],
        'solape de un minuto al final de la reserva' => ['2026-12-10 11:59', '2026-12-10 13:00', false],
        'solape de un minuto al inicio de la reserva' => ['2026-12-10 09:00', '2026-12-10 10:01', false],
        'pegada justo después' => ['2026-12-10 12:00', '2026-12-10 13:00', true],
        'pegada justo antes' => ['2026-12-10 09:00', '2026-12-10 10:00', true],
        'contenida en la reserva' => ['2026-12-10 10:30', '2026-12-10 11:00', false],
        'contiene a la reserva' => ['2026-12-10 09:00', '2026-12-10 13:00', false],
        'a la misma hora de otro día' => ['2026-12-11 10:00', '2026-12-11 12:00', true],
    ]);

    test('a booking does not block a resource of another space', function () {
        $other = Resource::factory()->create();
        occupy($this->resource);

        expect($other->space_id)->not->toBe($this->resource->space_id)
            ->and(isAvailable($other))->toBeTrue();
    });

    test('a booking does not block another resource of the same space', function () {
        $sibling = Resource::factory()->for($this->resource->space)->create();
        occupy($this->resource);

        expect(isAvailable($sibling))->toBeTrue();
    });

    test('blocks according to the status of the booking', function (array $attributes, bool $free) {
        occupy($this->resource, attributes: $attributes);

        expect(isAvailable($this->resource))->toBe($free);
    })->with([
        'pendiente vigente' => [fn () => ['status' => BookingStatus::PendingPayment, 'expires_at' => now()->addMinute()], false],
        'pendiente caducada sin limpiar' => [fn () => ['status' => BookingStatus::PendingPayment, 'expires_at' => now()->subMinute()], true],
        'pendiente que caduca en este instante' => [fn () => ['status' => BookingStatus::PendingPayment, 'expires_at' => now()], true],
        'pendiente sin caducidad' => [fn () => ['status' => BookingStatus::PendingPayment, 'expires_at' => null], false],
        'confirmada' => [fn () => ['status' => BookingStatus::Confirmed, 'expires_at' => null], false],
        'completada' => [fn () => ['status' => BookingStatus::Completed, 'expires_at' => null], false],
        'cancelada' => [fn () => ['status' => BookingStatus::Cancelled, 'expires_at' => null], true],
        'caducada' => [fn () => ['status' => BookingStatus::Expired, 'expires_at' => now()->subMinute()], true],
    ]);

    test('every status of the enum is covered by the blocking rule', function () {
        expect(BookingStatus::blocking())->toBe([
            BookingStatus::PendingPayment,
            BookingStatus::Confirmed,
            BookingStatus::Completed,
        ]);
    });

    test('a soft deleted booking does not block', function () {
        occupy($this->resource)->delete();

        expect(isAvailable($this->resource))->toBeTrue();
    });

    test('a booking with several resources blocks each of them', function () {
        $second = Resource::factory()->create();
        $start = Carbon::parse('2026-12-10 10:00');
        $end = Carbon::parse('2026-12-10 12:00');

        Booking::factory()->confirmed()
            ->withSlot($this->resource, $start, $end)
            ->withSlot($second, $start, $end)
            ->create();

        expect(isAvailable($this->resource))->toBeFalse()
            ->and(isAvailable($second))->toBeFalse();
    });

    test('each resource of a booking blocks only its own slot', function () {
        $second = Resource::factory()->create();

        Booking::factory()->confirmed()
            ->withSlot($this->resource, Carbon::parse('2026-12-10 10:00'), Carbon::parse('2026-12-10 12:00'))
            ->withSlot($second, Carbon::parse('2026-12-10 16:00'), Carbon::parse('2026-12-10 18:00'))
            ->create();

        expect(isAvailable($second))->toBeTrue()
            ->and(isAvailable($this->resource, '2026-12-10 16:00', '2026-12-10 18:00'))->toBeTrue();
    });

    test('can ignore a given booking', function () {
        $booking = occupy($this->resource);

        expect(isAvailable($this->resource, ignoreBookingId: $booking->id))->toBeTrue();
    });

    test('ignoring a booking does not ignore the others', function () {
        $ignored = occupy($this->resource);
        occupy($this->resource, '2026-12-10 11:00', '2026-12-10 13:00');

        expect(isAvailable($this->resource, ignoreBookingId: $ignored->id))->toBeFalse();
    });

    test('lists the free resources and combines with other scopes', function () {
        $busy = Resource::factory()->for($this->resource->space)->create();
        $retired = Resource::factory()->for($this->resource->space)->create(['active' => false]);
        occupy($busy);

        $ids = Resource::query()
            ->active()
            ->forSpace($this->resource->space_id)
            ->available(Carbon::parse('2026-12-10 10:00'), Carbon::parse('2026-12-10 12:00'))
            ->pluck('id')
            ->all();

        expect($ids)->toBe([$this->resource->id])
            ->not->toContain($busy->id, $retired->id);
    });

    test('runs a single query with a not exists subquery', function () {
        occupy($this->resource);

        DB::enableQueryLog();
        isAvailable($this->resource);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($queries)->toHaveCount(1)
            ->and($queries[0]['query'])->toContain('not exists');
    });
});
