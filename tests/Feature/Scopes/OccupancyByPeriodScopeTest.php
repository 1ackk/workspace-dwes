<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\Space;
use App\Services\Occupancy\ResourceOccupancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');

    // Horario de fábrica: de 08:00 a 20:00, 12 horas al día.
    $this->resource = Resource::factory()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function reserveSlot(Resource $resource, string $start, string $end, array $attributes = []): Booking
{
    return Booking::factory()->confirmed()
        ->withSlot($resource, Carbon::parse($start), Carbon::parse($end))
        ->create($attributes);
}

function occupancyOf(Resource $resource, string $from = '2026-12-10 00:00', string $to = '2026-12-11 00:00'): ResourceOccupancy
{
    return Resource::query()
        ->whereKey($resource->id)
        ->occupancyByPeriod(Carbon::parse($from), Carbon::parse($to))
        ->firstOrFail()
        ->occupancy;
}

function occupancyQueries(): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    Resource::query()->occupancyByPeriod(Carbon::parse('2026-12-10'), Carbon::parse('2026-12-11'))->get();

    return count(DB::getQueryLog());
}

describe('booked hours', function () {
    test('a resource without bookings has 0 %', function () {
        $occupancy = occupancyOf($this->resource);

        expect($occupancy->bookedHours)->toBe(0.0)
            ->and($occupancy->availableHours)->toBe(12.0)
            ->and($occupancy->percentage())->toBe(0.0);
    });

    test('a booking inside the period counts entirely', function () {
        reserveSlot($this->resource, '2026-12-10 10:00', '2026-12-10 12:00');

        $occupancy = occupancyOf($this->resource);

        expect($occupancy->bookedHours)->toBe(2.0)
            ->and($occupancy->percentage())->toBe(16.67);
    });

    test('slots that cross the start and the end of the period are clipped', function () {
        reserveSlot($this->resource, '2026-12-10 09:00', '2026-12-10 11:00');
        reserveSlot($this->resource, '2026-12-10 13:00', '2026-12-10 15:00');

        $occupancy = occupancyOf($this->resource, '2026-12-10 10:00', '2026-12-10 14:00');

        expect($occupancy->bookedHours)->toBe(2.0)
            ->and($occupancy->availableHours)->toBe(4.0)
            ->and($occupancy->percentage())->toBe(50.0);
    });

    test('a slot that only touches the edge of the period does not count', function () {
        reserveSlot($this->resource, '2026-12-10 08:00', '2026-12-10 10:00');
        reserveSlot($this->resource, '2026-12-10 12:00', '2026-12-10 14:00');

        expect(occupancyOf($this->resource, '2026-12-10 10:00', '2026-12-10 12:00')->bookedHours)->toBe(0.0);
    });

    test('two bookings in a row add up', function () {
        reserveSlot($this->resource, '2026-12-10 10:00', '2026-12-10 12:00');
        reserveSlot($this->resource, '2026-12-10 12:00', '2026-12-10 13:30');

        expect(occupancyOf($this->resource)->bookedHours)->toBe(3.5);
    });

    test('bookings that do not hold their slot do not count', function (array $attributes) {
        reserveSlot($this->resource, '2026-12-10 10:00', '2026-12-10 12:00', $attributes);

        expect(occupancyOf($this->resource)->bookedHours)->toBe(0.0);
    })->with([
        'cancelled' => [['status' => BookingStatus::Cancelled]],
        'expired' => [['status' => BookingStatus::Expired]],
        'lapsed pending' => [['status' => BookingStatus::PendingPayment, 'expires_at' => '2026-12-01 08:59:00']],
        'soft deleted' => [['deleted_at' => '2026-11-20 10:00:00']],
    ]);

    test('bookings that hold their slot count', function (array $attributes) {
        reserveSlot($this->resource, '2026-12-10 10:00', '2026-12-10 12:00', $attributes);

        expect(occupancyOf($this->resource)->bookedHours)->toBe(2.0);
    })->with([
        'pending still held' => [['status' => BookingStatus::PendingPayment, 'expires_at' => '2026-12-01 09:10:00']],
        'completed' => [['status' => BookingStatus::Completed]],
    ]);

    test('the slots of one resource are not mixed with those of another', function () {
        $other = Resource::factory()->create();
        reserveSlot($other, '2026-12-10 10:00', '2026-12-10 16:00');
        reserveSlot($this->resource, '2026-12-10 10:00', '2026-12-10 11:00');

        expect(occupancyOf($this->resource)->bookedHours)->toBe(1.0)
            ->and(occupancyOf($other)->bookedHours)->toBe(6.0);
    });
});

describe('available hours', function () {
    test('a period of several days adds the opening hours of each day', function () {
        expect(occupancyOf($this->resource, '2026-12-10 00:00', '2026-12-13 00:00')->availableHours)->toBe(36.0);
    });

    test('it uses the opening hours of the space of each resource', function () {
        $space = Space::factory()->create(['opens_at' => '09:00:00', 'closes_at' => '14:00:00']);
        $resource = Resource::factory()->for($space)->create();

        expect(occupancyOf($resource, '2026-12-10 00:00', '2026-12-13 00:00')->availableHours)->toBe(15.0);
    });

    test('a daylight saving change does not alter the number of days', function () {
        // El 25 de octubre de 2026 tiene 25 horas en Madrid.
        expect(occupancyOf($this->resource, '2026-10-24 00:00', '2026-10-27 00:00')->availableHours)->toBe(36.0);
    });

    test('a partial period inside a day gives the real occupancy', function () {
        reserveSlot($this->resource, '2026-12-10 10:00', '2026-12-10 12:00');

        $occupancy = occupancyOf($this->resource, '2026-12-10 10:00', '2026-12-10 12:00');

        expect($occupancy->availableHours)->toBe(2.0)
            ->and($occupancy->percentage())->toBe(100.0);
    });

    test('a period that starts and ends mid-day only counts the part inside the opening hours', function () {
        // Día 10: de 14 a 20 (6 h). Día 11: completo (12 h). Día 12: de 8 a 10 (2 h).
        expect(occupancyOf($this->resource, '2026-12-10 14:00', '2026-12-12 10:00')->availableHours)->toBe(20.0);
    });

    test('a period partly outside the opening hours is cut to them', function () {
        expect(occupancyOf($this->resource, '2026-12-10 06:00', '2026-12-10 09:00')->availableHours)->toBe(1.0)
            ->and(occupancyOf($this->resource, '2026-12-10 19:00', '2026-12-10 23:00')->availableHours)->toBe(1.0);
    });

    test('a period completely outside the opening hours has 0 hours and 0 %', function () {
        $occupancy = occupancyOf($this->resource, '2026-12-10 21:00', '2026-12-10 23:00');

        expect($occupancy->availableHours)->toBe(0.0)
            ->and($occupancy->percentage())->toBe(0.0);
    });
});

describe('period', function () {
    test('an end that is not after the start is rejected', function (string $to) {
        Resource::query()->occupancyByPeriod(Carbon::parse('2026-12-10 10:00'), Carbon::parse($to));
    })->with(['2026-12-10 10:00', '2026-12-10 09:00'])
        ->throws(InvalidArgumentException::class, 'El final del periodo debe ser posterior a su inicio.');

    test('dates in another timezone are read in the application timezone', function () {
        reserveSlot($this->resource, '2026-12-10 10:00', '2026-12-10 12:00');

        // De 09:00 a 11:00 UTC son de 10:00 a 12:00 en Madrid.
        $occupancy = Resource::query()->whereKey($this->resource->id)
            ->occupancyByPeriod(Carbon::parse('2026-12-10 09:00', 'UTC'), Carbon::parse('2026-12-10 11:00', 'UTC'))
            ->firstOrFail()
            ->occupancy;

        expect($occupancy->bookedHours)->toBe(2.0)
            ->and($occupancy->availableHours)->toBe(2.0);
    });
});

describe('result', function () {
    test('every resource is returned and inactive ones can be left out by chaining active', function () {
        $retired = Resource::factory()->inactive()->create();
        $period = [Carbon::parse('2026-12-10'), Carbon::parse('2026-12-11')];

        expect(Resource::query()->occupancyByPeriod(...$period)->pluck('id')->all())->toContain($retired->id)
            ->and(Resource::query()->occupancyByPeriod(...$period)->active()->get()->modelKeys())->not->toContain($retired->id);
    });

    test('the number of queries does not depend on the number of resources', function () {
        reserveSlot($this->resource, '2026-12-10 10:00', '2026-12-10 12:00');
        $withOneResource = occupancyQueries();

        Resource::factory()->count(6)->create()
            ->each(fn (Resource $resource) => reserveSlot($resource, '2026-12-10 10:00', '2026-12-10 12:00'));

        expect(occupancyQueries())->toBe($withOneResource)->toBe(3);
    });

    test('occupancy is not an attribute: it is not serialized and does not break a later save', function () {
        $resource = Resource::query()->whereKey($this->resource->id)
            ->occupancyByPeriod(Carbon::parse('2026-12-10'), Carbon::parse('2026-12-11'))
            ->firstOrFail();

        expect($resource->occupancy)->toBeInstanceOf(ResourceOccupancy::class)
            ->and($resource->getAttributes())->not->toHaveKey('occupancy')
            ->and($resource->toArray())->not->toHaveKey('occupancy');

        $resource->name = 'Renombrado';

        expect($resource->save())->toBeTrue()
            ->and($this->resource->fresh()->name)->toBe('Renombrado');
    });

    test('a resource that did not go through the scope has no occupancy', function () {
        expect($this->resource->occupancy)->toBeNull();
    });

    test('pluck and cursor do not break, and cursor does not invent an occupancy without its relations', function () {
        $period = [Carbon::parse('2026-12-10'), Carbon::parse('2026-12-11')];

        expect(Resource::query()->occupancyByPeriod(...$period)->pluck('name')->all())->toBe([$this->resource->name])
            ->and(Resource::query()->occupancyByPeriod(...$period)->cursor()->first()->occupancy)->toBeNull();
    });
});
