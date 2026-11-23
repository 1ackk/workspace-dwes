<?php

use App\Enums\BookingStatus;
use App\Enums\CellState;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\Space;
use App\Services\Booking\AvailabilityGrid;
use App\Services\Booking\GridCell;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');

    $this->grid = app(AvailabilityGrid::class);
    $this->resource = Resource::factory()->for(Space::factory()->create(['opens_at' => '08:00:00', 'closes_at' => '20:00:00']))->create();
});

/**
 * @return array<string, CellState>
 */
function statesOf(Resource $resource, string $day): array
{
    $states = [];

    foreach (app(AvailabilityGrid::class)->cells($resource->fresh(), Carbon::parse($day)) as $cell) {
        $states[$cell->key()] = $cell->state;
    }

    return $states;
}

function bookedSlot(Resource $resource, string $start, string $end, string $state = 'confirmed', array $attributes = []): Booking
{
    return Booking::factory()->{$state}()
        ->withSlot($resource, Carbon::parse($start), Carbon::parse($end))
        ->create($attributes);
}

test('has one 30-minute cell from opening to closing time', function () {
    $cells = $this->grid->cells($this->resource, Carbon::parse('2026-12-02'));

    expect($cells)->toHaveCount(24)
        ->and($cells[0]->key())->toBe('08:00')
        ->and($cells[0]->end->format('H:i'))->toBe('08:30')
        ->and(end($cells)->key())->toBe('19:30')
        ->and(end($cells)->end->format('H:i'))->toBe('20:00')
        ->and(collect($cells)->every(fn (GridCell $cell) => $cell->state === CellState::Free))->toBeTrue();
});

test('follows the opening hours of the space of the resource', function () {
    $short = Resource::factory()->for(Space::factory()->create(['opens_at' => '09:00:00', 'closes_at' => '13:00:00']))->create();

    expect(array_keys(statesOf($short, '2026-12-02')))->toBe(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30']);
});

test('the cells are in the application timezone', function () {
    $cells = $this->grid->cells($this->resource, Carbon::parse('2026-12-02'));

    expect($cells[0]->start->getTimezone()->getName())->toBe('Europe/Madrid');
});

test('a confirmed booking makes its cells busy, with half-open slots', function () {
    bookedSlot($this->resource, '2026-12-02 10:00', '2026-12-02 11:00');

    $states = statesOf($this->resource, '2026-12-02');

    expect($states['09:30'])->toBe(CellState::Free)
        ->and($states['10:00'])->toBe(CellState::Busy)
        ->and($states['10:30'])->toBe(CellState::Busy)
        ->and($states['11:00'])->toBe(CellState::Free);
});

test('applies the same blocking rules as available()', function (string $state, array $attributes, CellState $expected) {
    bookedSlot($this->resource, '2026-12-02 12:00', '2026-12-02 12:30', $state, $attributes);

    expect(statesOf($this->resource, '2026-12-02')['12:00'])->toBe($expected);
})->with([
    'completada' => ['confirmed', ['status' => BookingStatus::Completed], CellState::Busy],
    'pendiente con retención vigente' => ['expired', ['status' => BookingStatus::PendingPayment, 'expires_at' => '2026-12-01 09:10:00'], CellState::Busy],
    'pendiente con retención vencida' => ['expired', ['status' => BookingStatus::PendingPayment, 'expires_at' => '2026-12-01 08:59:00'], CellState::Free],
    'cancelada' => ['cancelled', [], CellState::Free],
    'caducada' => ['expired', [], CellState::Free],
]);

test('a soft deleted booking or one of another resource does not block', function () {
    bookedSlot($this->resource, '2026-12-02 12:00', '2026-12-02 13:00')->delete();
    bookedSlot(Resource::factory()->create(), '2026-12-02 14:00', '2026-12-02 15:00');

    $states = statesOf($this->resource, '2026-12-02');

    expect($states['12:00'])->toBe(CellState::Free)
        ->and($states['14:00'])->toBe(CellState::Free);
});

test('cells that start now or earlier are past, even if they are busy', function () {
    bookedSlot($this->resource, '2026-12-01 08:00', '2026-12-01 10:00');

    $states = statesOf($this->resource, '2026-12-01');

    expect($states['08:00'])->toBe(CellState::Past)
        ->and($states['08:30'])->toBe(CellState::Past)
        ->and($states['09:00'])->toBe(CellState::Past)
        ->and($states['09:30'])->toBe(CellState::Busy)
        ->and($states['10:00'])->toBe(CellState::Free);
});

test('a booking that crosses the opening time counts for the first cells', function () {
    $early = Resource::factory()->for(Space::factory()->create(['opens_at' => '10:00:00', 'closes_at' => '12:00:00']))->create();
    bookedSlot($early, '2026-12-02 09:00', '2026-12-02 10:30');

    expect(statesOf($early, '2026-12-02'))->toBe([
        '10:00' => CellState::Busy,
        '10:30' => CellState::Free,
        '11:00' => CellState::Free,
        '11:30' => CellState::Free,
    ]);
});

test('runs a constant number of queries however many bookings there are', function () {
    // El recurso llega sin su espacio cargado: cuenta la consulta del espacio y la de las franjas.
    $queriesFor = function (Resource $resource): int {
        $fresh = $resource->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->grid->cells($fresh, Carbon::parse('2026-12-02'));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    bookedSlot($this->resource, '2026-12-02 08:00', '2026-12-02 08:30');
    $withOne = $queriesFor($this->resource);

    collect(range(0, 22))->each(fn (int $n) => bookedSlot(
        $this->resource,
        Carbon::parse('2026-12-02 08:30')->addMinutes(30 * $n)->toDateTimeString(),
        Carbon::parse('2026-12-02 09:00')->addMinutes(30 * $n)->toDateTimeString(),
    ));
    $withMany = $queriesFor($this->resource);

    expect($withOne)->toBe(2)
        ->and($withMany)->toBe($withOne)
        ->and(collect(statesOf($this->resource, '2026-12-02'))->every(fn (CellState $state) => $state === CellState::Busy))->toBeTrue();
});
