<?php

use App\Enums\BookingStatus;
use App\Exceptions\BookingException;
use App\Exceptions\InvalidBookingRequestException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\Resource;
use App\Models\Space;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Pricing\InvalidSlotException;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\Slot;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    // Martes. Las franjas de los tests son del jueves 10/12/2026 si no se indica otra cosa.
    Carbon::setTestNow('2026-12-01 09:00:00');

    $this->user = User::factory()->create();
    $this->service = app(BookingService::class);
    $this->room = Resource::factory()->room()->create(['name' => 'Sala Azul']);
});

function slotAt(Resource $resource, string $from = '10:00', string $to = '12:00', string $day = '2026-12-10'): Slot
{
    return new Slot($resource, Carbon::parse("{$day} {$from}"), Carbon::parse("{$day} {$to}"));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function existingBooking(Resource $resource, string $from = '10:00', string $to = '12:00', array $attributes = []): Booking
{
    return Booking::factory()->confirmed()
        ->withSlot($resource, Carbon::parse("2026-12-10 {$from}"), Carbon::parse("2026-12-10 {$to}"))
        ->create($attributes);
}

function expectRowCounts(int $bookings, int $slots): void
{
    expect(Booking::withTrashed()->count())->toBe($bookings)
        ->and(DB::table('booking_resource')->count())->toBe($slots);
}

describe('a successful booking', function () {
    test('with one resource', function () {
        $booking = $this->service->createPending($this->user, [slotAt($this->room)]);
        $pivot = BookingResource::query()->sole();

        expect($booking->exists)->toBeTrue()
            ->and($booking->user_id)->toBe($this->user->id)
            ->and($booking->status)->toBe(BookingStatus::PendingPayment)
            ->and($booking->expires_at->toDateTimeString())->toBe('2026-12-01 09:15:00')
            ->and($booking->date->toDateString())->toBe('2026-12-10')
            ->and($booking->total)->toBe('24.00')
            ->and($booking->cancelled_at)->toBeNull()
            ->and($pivot->booking_id)->toBe($booking->id)
            ->and($pivot->resource_id)->toBe($this->room->id)
            ->and($pivot->start_at->toDateTimeString())->toBe('2026-12-10 10:00:00')
            ->and($pivot->end_at->toDateTimeString())->toBe('2026-12-10 12:00:00')
            ->and($pivot->precio_final)->toBe('24.00');
    });

    test('with several resources, each one priced by the calculator', function () {
        Tariff::factory()->afternoon()->create();
        $projector = Resource::factory()->projector()->create();
        $first = slotAt($this->room, '14:00', '16:00');
        $second = slotAt($projector, '16:00', '17:30');

        $booking = $this->service->createPending($this->user, [$first, $second]);

        $calculator = app(PriceCalculator::class);
        $firstPrice = $calculator->priceSlot($this->room, $first->start, $first->end)->decimal();
        $secondPrice = $calculator->priceSlot($projector, $second->start, $second->end)->decimal();

        $prices = BookingResource::query()->pluck('precio_final', 'resource_id');

        expect($prices[$this->room->id])->toBe($firstPrice)->toBe('25.80')
            ->and($prices[$projector->id])->toBe($secondPrice)->toBe('8.63')
            ->and($booking->total)->toBe('34.43')
            ->and($booking->resources)->toHaveCount(2);
    });

    test('the hold duration comes from the configuration', function () {
        config(['booking.hold_minutes' => 5]);

        $booking = $this->service->createPending($this->user, [slotAt($this->room)]);

        expect($booking->expires_at->toDateTimeString())->toBe('2026-12-01 09:05:00');
    });

    test('the date is the day of the earliest slot, whatever the order of the list', function () {
        $booking = $this->service->createPending($this->user, [
            slotAt($this->room, day: '2026-12-12'),
            slotAt($this->room, day: '2026-12-10'),
        ]);

        expect($booking->date->toDateString())->toBe('2026-12-10');
    });

    test('allows disjoint and adjacent slots on the same resource', function () {
        $this->service->createPending($this->user, [
            slotAt($this->room, '16:00', '17:00'),
            slotAt($this->room, '10:00', '12:00'),
            slotAt($this->room, '12:00', '13:00'),
        ]);

        expectRowCounts(1, 3);
    });

    test('stores slots given in another timezone in the time of the application', function () {
        $booking = $this->service->createPending($this->user, [
            new Slot($this->room, Carbon::parse('2026-12-10 09:00', 'UTC'), Carbon::parse('2026-12-10 10:00', 'UTC')),
        ]);

        expect(DB::table('booking_resource')->value('start_at'))->toBe('2026-12-10 10:00:00')
            ->and($booking->total)->toBe('12.00');
    });

    test('uses the current data of the resource, not the model it receives', function () {
        $stale = Resource::query()->find($this->room->id);
        Resource::query()->whereKey($this->room->id)->update(['hourly_rate' => 20]);

        $booking = $this->service->createPending($this->user, [slotAt($stale)]);

        expect($booking->total)->toBe('40.00');
    });
});

describe('availability', function () {
    test('rejects a slot taken by a blocking booking and saves nothing', function (array $attributes) {
        existingBooking($this->room, attributes: $attributes);

        expect(fn () => $this->service->createPending($this->user, [slotAt($this->room)]))
            ->toThrow(SlotUnavailableException::class);

        expectRowCounts(1, 1);
    })->with([
        'confirmada' => [fn () => ['status' => BookingStatus::Confirmed, 'expires_at' => null]],
        'pendiente vigente' => [fn () => ['status' => BookingStatus::PendingPayment, 'expires_at' => now()->addMinute()]],
        'completada' => [fn () => ['status' => BookingStatus::Completed, 'expires_at' => null]],
    ]);

    test('accepts a slot held by a booking that does not block', function (array $attributes) {
        existingBooking($this->room, attributes: $attributes);

        $this->service->createPending($this->user, [slotAt($this->room)]);

        expectRowCounts(2, 2);
    })->with([
        'pendiente caducada sin limpiar' => [fn () => ['status' => BookingStatus::PendingPayment, 'expires_at' => now()->subMinute()]],
        'cancelada' => [fn () => ['status' => BookingStatus::Cancelled, 'expires_at' => null]],
        'caducada' => [fn () => ['status' => BookingStatus::Expired, 'expires_at' => now()->subMinute()]],
    ]);

    test('the exception names the resource and the slot', function () {
        existingBooking($this->room);
        $exception = null;

        try {
            $this->service->createPending($this->user, [slotAt($this->room)]);
        } catch (SlotUnavailableException $caught) {
            $exception = $caught;
        }

        expect($exception->slot->resource->id)->toBe($this->room->id)
            ->and($exception->getMessage())->toBe(__(':resource no está disponible el :date de :start a :end.', [
                'resource' => 'Sala Azul',
                'date' => '10/12/2026',
                'start' => '10:00',
                'end' => '12:00',
            ]));
    });

    test('a slot adjacent to a booking is allowed', function (string $from, string $to) {
        existingBooking($this->room);

        $this->service->createPending($this->user, [slotAt($this->room, $from, $to)]);

        expectRowCounts(2, 2);
    })->with([
        'justo después' => ['12:00', '13:00'],
        'justo antes' => ['09:00', '10:00'],
    ]);

    test('an overlap of a single minute is rejected', function (string $bookedFrom, string $bookedTo, string $from, string $to) {
        existingBooking($this->room, $bookedFrom, $bookedTo);

        expect(fn () => $this->service->createPending($this->user, [slotAt($this->room, $from, $to)]))
            ->toThrow(SlotUnavailableException::class);
    })->with([
        'la reserva acaba un minuto tarde' => ['10:00', '12:01', '12:00', '13:00'],
        'la reserva empieza un minuto antes' => ['12:59', '14:00', '12:00', '13:00'],
    ]);
});

describe('atomicity', function () {
    test('if the second or third slot fails, no row is left', function (int $position, string $reason) {
        $resources = Resource::factory()->count(3)->create()->all();
        $slots = array_map(fn (Resource $resource) => slotAt($resource), $resources);

        if ($reason === 'inactive') {
            $resources[$position]->update(['active' => false]);
        } else {
            $slots[$position] = slotAt($resources[$position], '20:00', '21:00');
        }

        expect(fn () => $this->service->createPending($this->user, $slots))
            ->toThrow(BookingException::class);

        expectRowCounts(0, 0);
    })->with([
        'segunda franja, recurso inactivo' => [1, 'inactive'],
        'tercera franja, recurso inactivo' => [2, 'inactive'],
        'segunda franja, fuera de horario' => [1, 'hours'],
        'tercera franja, fuera de horario' => [2, 'hours'],
    ]);

    test('three resources and one taken: nothing is saved for the other two', function (int $position) {
        $resources = Resource::factory()->count(3)->create()->all();
        existingBooking($resources[$position]);

        expect(fn () => $this->service->createPending($this->user, array_map(fn (Resource $resource) => slotAt($resource), $resources)))
            ->toThrow(SlotUnavailableException::class);

        expectRowCounts(1, 1);
    })->with([
        'la segunda' => 1,
        'la tercera' => 2,
    ]);

    test('a failure while inserting the slots rolls everything back', function (int $failingInsert) {
        $resources = Resource::factory()->count(3)->create()->all();
        $inserts = 0;

        Event::listen('eloquent.creating: '.BookingResource::class, function () use (&$inserts, $failingInsert): void {
            if (++$inserts === $failingInsert) {
                throw new RuntimeException('Fallo al insertar');
            }
        });

        expect(fn () => $this->service->createPending($this->user, array_map(fn (Resource $resource) => slotAt($resource), $resources)))
            ->toThrow(RuntimeException::class, 'Fallo al insertar');

        expect($inserts)->toBe($failingInsert);
        expectRowCounts(0, 0);
    })->with([
        'al insertar la segunda fila' => 2,
        'al insertar la tercera fila' => 3,
    ]);
});

describe('validation', function () {
    test('an inactive resource is rejected', function () {
        $this->room->update(['active' => false]);

        expect(fn () => $this->service->createPending($this->user, [slotAt($this->room)]))
            ->toThrow(InvalidBookingRequestException::class, __(':resource no se puede reservar porque no está activo.', ['resource' => 'Sala Azul']));

        expectRowCounts(0, 0);
    });

    test('checks the active flag in the database, not in the model it receives', function () {
        $stale = Resource::query()->find($this->room->id);
        Resource::query()->whereKey($this->room->id)->update(['active' => false]);

        expect(fn () => $this->service->createPending($this->user, [slotAt($stale)]))
            ->toThrow(InvalidBookingRequestException::class);
    });

    test('a resource deleted just before the lock is rejected and nothing is saved', function () {
        $stale = Resource::query()->find($this->room->id);
        Resource::query()->whereKey($this->room->id)->delete();

        expect(fn () => $this->service->createPending($this->user, [slotAt($stale)]))
            ->toThrow(InvalidBookingRequestException::class, __(':resource no se puede reservar porque no está activo.', ['resource' => 'Sala Azul']));

        expectRowCounts(0, 0);
    });

    test('rejects slots outside the opening hours of the space', function (string $from, string $to) {
        expect(fn () => $this->service->createPending($this->user, [slotAt($this->room, $from, $to)]))
            ->toThrow(InvalidSlotException::class, __(':resource solo se puede reservar de :opens a :closes.', [
                'resource' => 'Sala Azul',
                'opens' => '08:00',
                'closes' => '20:00',
            ]));

        expectRowCounts(0, 0);
    })->with([
        'empieza antes de abrir' => ['07:30', '09:00'],
        'entera antes de abrir' => ['07:00', '07:30'],
        'acaba tras cerrar' => ['19:30', '20:30'],
        'entera tras cerrar' => ['20:00', '21:00'],
    ]);

    test('accepts slots at the exact limits of the opening hours', function (string $from, string $to) {
        $this->service->createPending($this->user, [slotAt($this->room, $from, $to)]);

        expectRowCounts(1, 1);
    })->with([
        'empieza al abrir' => ['08:00', '09:00'],
        'acaba al cerrar' => ['19:30', '20:00'],
        'todo el horario' => ['08:00', '20:00'],
    ]);

    test('uses the opening hours of the space of each resource', function () {
        $early = Resource::factory()
            ->for(Space::factory()->state(['opens_at' => '06:00:00', 'closes_at' => '14:00:00']))
            ->create();

        $this->service->createPending($this->user, [slotAt($early, '06:00', '07:00')]);

        expect(fn () => $this->service->createPending($this->user, [slotAt($early, '13:30', '14:30')]))
            ->toThrow(InvalidSlotException::class);

        expectRowCounts(1, 1);
    });

    test('rejects slots off the grid of 30 minutes', function (string $from, string $to) {
        expect(fn () => $this->service->createPending($this->user, [slotAt($this->room, $from, $to)]))
            ->toThrow(InvalidSlotException::class, __('Las franjas deben empezar y terminar en punto o a y media.'));

        expectRowCounts(0, 0);
    })->with([
        'inicio a y cuarto' => ['10:15', '11:00'],
        'fin menos cuarto' => ['10:00', '10:45'],
        'un minuto fuera' => ['10:01', '11:00'],
        'con segundos' => ['10:00:30', '11:00'],
        'con segundos en el fin' => ['10:00', '11:00:30'],
    ]);

    test('the minimum duration is one block', function () {
        $this->service->createPending($this->user, [slotAt($this->room, '10:00', '10:30')]);

        expectRowCounts(1, 1);
    });

    test('rejects an end not after the start', function (string $from, string $to) {
        expect(fn () => $this->service->createPending($this->user, [slotAt($this->room, $from, $to)]))
            ->toThrow(InvalidSlotException::class, __('El fin de la franja debe ser posterior a su inicio.'));
    })->with([
        'iguales' => ['10:00', '10:00'],
        'al revés' => ['12:00', '10:00'],
    ]);

    test('rejects a slot that crosses midnight', function () {
        $slot = new Slot($this->room, Carbon::parse('2026-12-10 19:00'), Carbon::parse('2026-12-11 09:00'));

        expect(fn () => $this->service->createPending($this->user, [$slot]))
            ->toThrow(InvalidSlotException::class, __('La franja debe empezar y terminar el mismo día.'));
    });

    test('rejects a slot that has already started', function (string $from, string $to, string $day) {
        expect(fn () => $this->service->createPending($this->user, [slotAt($this->room, $from, $to, $day)]))
            ->toThrow(InvalidSlotException::class, __('La franja debe empezar después del momento actual.'));

        expectRowCounts(0, 0);
    })->with([
        'ayer' => ['10:00', '11:00', '2026-11-30'],
        'hoy, antes de ahora' => ['08:30', '09:30', '2026-12-01'],
        'empieza justo ahora' => ['09:00', '10:00', '2026-12-01'],
    ]);

    test('accepts the next block after now', function () {
        $this->service->createPending($this->user, [slotAt($this->room, '09:30', '10:00', '2026-12-01')]);

        expectRowCounts(1, 1);
    });

    test('rejects an empty list', function () {
        expect(fn () => $this->service->createPending($this->user, []))
            ->toThrow(InvalidBookingRequestException::class, __('La reserva debe incluir al menos una franja.'));

        expectRowCounts(0, 0);
    });

    test('rejects slots of the same request that overlap on the same resource', function (array $first, array $second) {
        $slots = [slotAt($this->room, ...$first), slotAt($this->room, ...$second)];

        expect(fn () => $this->service->createPending($this->user, $slots))
            ->toThrow(InvalidBookingRequestException::class, __('Las franjas de :resource se solapan entre sí.', ['resource' => 'Sala Azul']));

        expectRowCounts(0, 0);
    })->with([
        'idénticas' => [['10:00', '12:00'], ['10:00', '12:00']],
        'solape parcial' => [['10:00', '12:00'], ['11:00', '13:00']],
        'desordenadas' => [['11:00', '13:00'], ['10:00', '12:00']],
        'una contiene a la otra' => [['10:00', '14:00'], ['11:00', '12:00']],
    ]);

    test('the same slot on different resources is allowed', function () {
        $other = Resource::factory()->create();

        $this->service->createPending($this->user, [slotAt($this->room), slotAt($other)]);

        expectRowCounts(1, 2);
    });

    test('all the domain errors share the same base class', function (string $exception) {
        expect(is_subclass_of($exception, BookingException::class))->toBeTrue();
    })->with([
        InvalidSlotException::class,
        InvalidBookingRequestException::class,
        SlotUnavailableException::class,
    ]);
});

test('a later change of tariff does not alter a created booking', function () {
    Tariff::factory()->afternoon()->create();
    $booking = $this->service->createPending($this->user, [slotAt($this->room, '16:00', '17:00')]);

    Tariff::query()->update(['surcharge_percent' => 90]);
    $this->room->update(['hourly_rate' => 99]);

    expect(BookingResource::query()->sole()->precio_final)->toBe('13.80')
        ->and($booking->fresh()->total)->toBe('13.80');
});

describe('locking', function () {
    test('the first query is the lock, in a single query ordered by ascending id', function () {
        [$first, $second, $third] = Resource::factory()->count(3)->create()->all();

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $this->service->createPending($this->user, [
            slotAt($third),
            slotAt($first),
            slotAt($second),
        ]);

        $lockingQueries = array_filter(
            $queries,
            fn (QueryExecuted $query) => str_contains(withoutEngineSyntax($query->sql), 'from resources where resources.id in'),
        );

        $firstSql = withoutEngineSyntax($queries[0]->sql);

        // whereKey escribe los ids enteros directamente en el SQL, no como parámetros.
        preg_match('/id in \(([\d, ]+)\)/', $firstSql, $matches);

        expect($firstSql)->toStartWith('select * from resources where resources.id in (')
            ->toEndWith('order by id asc')
            ->and(array_map(intval(...), explode(', ', $matches[1])))
            ->toEqualCanonicalizing([$first->id, $second->id, $third->id])
            ->and($lockingQueries)->toHaveCount(1);

        // SQLite no escribe el bloqueo; en el resto de motores tiene que estar.
        if (DB::getDriverName() !== 'sqlite') {
            expect($queries[0]->sql)->toEndWith('for update');
        }
    });

    test('a request rejected before the lock does not touch the database', function () {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        expect(fn () => $this->service->createPending($this->user, [slotAt($this->room, '10:15', '11:00')]))
            ->toThrow(InvalidSlotException::class);

        expect($queries)->toBe(0);
    });

    test('runs the whole operation inside a transaction', function () {
        $levels = [];
        Event::listen('eloquent.creating: '.Booking::class, function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });
        $levelBefore = DB::transactionLevel();

        $this->service->createPending($this->user, [slotAt($this->room)]);

        expect($levels)->toBe([$levelBefore + 1]);
    });
});

test('with a slot the factory keeps the date of the earliest slot', function () {
    $booking = Booking::factory()
        ->withSlot($this->room, Carbon::parse('2026-12-20 10:00'), Carbon::parse('2026-12-20 11:00'))
        ->withSlot(Resource::factory()->create(), Carbon::parse('2026-12-18 10:00'), Carbon::parse('2026-12-18 11:00'))
        ->create();

    expect($booking->fresh()->date->toDateString())->toBe('2026-12-18');
});
