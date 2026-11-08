<?php

use App\Models\Resource;
use App\Models\Tariff;
use App\Services\Pricing\InvalidSlotException;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\PriceSegment;
use App\Services\Pricing\Slot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// El 1/12/2026 es martes; el 5/12/2026, sábado, y el 6/12/2026, domingo.

function resourceAt(float $rate): Resource
{
    return Resource::factory()->create(['hourly_rate' => $rate]);
}

function priceOf(Resource $resource, string $start, string $end): string
{
    return app(PriceCalculator::class)
        ->priceSlot($resource, Carbon::parse($start), Carbon::parse($end))
        ->decimal();
}

function priceFromUtc(Resource $resource, string $start, string $end): string
{
    return app(PriceCalculator::class)
        ->priceSlot($resource, Carbon::parse($start, 'UTC'), Carbon::parse($end, 'UTC'))
        ->decimal();
}

test('uses the app timezone of Madrid', function () {
    expect(config('app.timezone'))->toBe('Europe/Madrid');
});

test('without tariffs the price is the hourly rate by the hours', function () {
    expect(priceOf(resourceAt(12), '2026-12-01 10:00', '2026-12-01 12:00'))->toBe('24.00');
});

describe('afternoon surcharge', function () {
    beforeEach(function () {
        Tariff::factory()->afternoon()->create();
        $this->room = resourceAt(12);
    });

    test('splits the slot at the borders of the tariff', function (string $start, string $end, string $expected) {
        expect(priceOf($this->room, "2026-12-01 {$start}", "2026-12-01 {$end}"))->toBe($expected);
    })->with([
        'entera dentro' => ['16:00', '18:00', '27.60'],
        'toda la tarifa' => ['15:00', '20:00', '69.00'],
        'cruza el borde de las 15:00' => ['14:30', '15:30', '12.90'],
        'cruza el borde de las 20:00' => ['19:30', '20:30', '12.90'],
        'el ejemplo de 14:00 a 16:00' => ['14:00', '16:00', '25.80'],
        'termina justo cuando empieza' => ['14:00', '15:00', '12.00'],
        'empieza justo cuando termina' => ['20:00', '21:00', '12.00'],
        'contiene toda la tarifa' => ['14:00', '21:00', '93.00'],
    ]);

    test('returns the breakdown by segments', function () {
        $price = app(PriceCalculator::class)->priceSlot(
            $this->room,
            Carbon::parse('2026-12-01 14:00'),
            Carbon::parse('2026-12-01 16:00'),
        );

        [$first, $second] = $price->segments;

        expect($price->segments)->toHaveCount(2)
            ->and($price->cents)->toBe(2580)
            ->and($first->start->format('H:i'))->toBe('14:00')
            ->and($first->end->format('H:i'))->toBe('15:00')
            ->and($first->baseCentsPerHour)->toBe(1200)
            ->and($first->surchargeBasisPoints)->toBe(0)
            ->and($first->decimal())->toBe('12.00')
            ->and($second->start->format('H:i'))->toBe('15:00')
            ->and($second->end->format('H:i'))->toBe('16:00')
            ->and($second->surchargeBasisPoints)->toBe(1500)
            ->and($second->decimal())->toBe('13.80');
    });

    test('a tariff without a schedule lasts all day', function () {
        Tariff::query()->update(['starts_at' => null, 'ends_at' => null]);

        expect(priceOf($this->room, '2026-12-01 09:00', '2026-12-01 10:00'))->toBe('13.80');
    });

    test('a tariff whose end is not after its start never applies', function () {
        Tariff::query()->update(['starts_at' => '20:00:00', 'ends_at' => '15:00:00']);

        $price = app(PriceCalculator::class)->priceSlot(
            $this->room,
            Carbon::parse('2026-12-01 14:00'),
            Carbon::parse('2026-12-01 21:00'),
        );

        expect($price->decimal())->toBe('84.00')
            ->and($price->segments)->toHaveCount(1);
    });
});

describe('weekend surcharge', function () {
    beforeEach(function () {
        Tariff::factory()->weekend()->create();
        $this->room = resourceAt(12);
    });

    test('applies only on saturday and sunday', function (string $date, string $expected) {
        expect(priceOf($this->room, "{$date} 10:00", "{$date} 12:00"))->toBe($expected);
    })->with([
        'viernes' => ['2026-12-04', '24.00'],
        'sábado' => ['2026-12-05', '28.80'],
        'domingo' => ['2026-12-06', '28.80'],
        'lunes' => ['2026-12-07', '24.00'],
    ]);

    test('adds up with the afternoon surcharge over the base, without compounding', function () {
        Tariff::factory()->afternoon()->create();

        expect(priceOf($this->room, '2026-12-05 16:00', '2026-12-05 18:00'))->toBe('32.40');
    });

    test('combines only in the part of the slot where both apply', function () {
        Tariff::factory()->afternoon()->create();

        expect(priceOf($this->room, '2026-12-05 14:00', '2026-12-05 16:00'))->toBe('30.60');
    });
});

describe('priority', function () {
    test('a tariff of the resource beats the general one of the same kind', function () {
        $room = resourceAt(25);
        Tariff::factory()->afternoon()->for($room)->create(['surcharge_percent' => 10]);
        Tariff::factory()->afternoon()->create();

        expect(priceOf($room, '2026-12-01 16:00', '2026-12-01 17:00'))->toBe('27.50');
    });

    test('a tariff of another resource does not apply', function () {
        $room = resourceAt(25);
        Tariff::factory()->afternoon()->for(resourceAt(10))->create(['surcharge_percent' => 50]);

        expect(priceOf($room, '2026-12-01 16:00', '2026-12-01 17:00'))->toBe('25.00');
    });

    test('a tariff of the resource does not cancel the general one of another kind', function () {
        $room = resourceAt(10);
        Tariff::factory()->afternoon()->for($room)->create(['surcharge_percent' => 10]);
        Tariff::factory()->weekend()->create();

        expect(priceOf($room, '2026-12-05 16:00', '2026-12-05 17:00'))->toBe('13.00');
    });

    test('the priority is decided segment by segment', function () {
        $room = resourceAt(25);
        Tariff::factory()->afternoon()->create();
        Tariff::factory()->afternoon()->for($room)->create(['surcharge_percent' => 10, 'ends_at' => '18:00:00']);

        expect(priceOf($room, '2026-12-01 17:00', '2026-12-01 19:00'))->toBe('56.25');
    });

    test('with the same priority the most recent tariff wins', function () {
        $room = resourceAt(10);
        Tariff::factory()->afternoon()->create(['surcharge_percent' => 50]);
        Tariff::factory()->afternoon()->create(['surcharge_percent' => 10]);

        expect(priceOf($room, '2026-12-01 16:00', '2026-12-01 17:00'))->toBe('11.00');
    });
});

describe('base tariff', function () {
    test('a base tariff of the resource replaces the hourly rate and the surcharges apply to it', function () {
        $desk = resourceAt(3);
        Tariff::factory()->for($desk)->create(['price_per_hour' => 2.50]);
        Tariff::factory()->create(['price_per_hour' => 4]);
        Tariff::factory()->weekend()->create();

        expect(priceOf($desk, '2026-12-05 10:00', '2026-12-05 11:30'))->toBe('4.50');
    });

    test('a general base tariff is always ignored', function () {
        $desk = resourceAt(3);
        Tariff::factory()->create(['price_per_hour' => 4]);

        expect(priceOf($desk, '2026-12-01 10:00', '2026-12-01 11:00'))->toBe('3.00');
    });

    test('a base tariff without a price is ignored', function () {
        $desk = resourceAt(3);
        Tariff::factory()->for($desk)->create();

        expect(priceOf($desk, '2026-12-01 10:00', '2026-12-01 11:00'))->toBe('3.00');
    });

    test('a base tariff with a schedule replaces the rate only inside it', function () {
        $desk = resourceAt(3);
        Tariff::factory()->for($desk)->create(['price_per_hour' => 2, 'starts_at' => '10:00:00', 'ends_at' => '11:00:00']);

        expect(priceOf($desk, '2026-12-01 10:00', '2026-12-01 12:00'))->toBe('5.00');
    });
});

describe('validity', function () {
    test('checks the validity dates, including both limits', function (?string $from, ?string $until, string $expected) {
        Tariff::factory()->afternoon()->create(['valid_from' => $from, 'valid_until' => $until]);

        expect(priceOf(resourceAt(10), '2026-12-10 16:00', '2026-12-10 17:00'))->toBe($expected);
    })->with([
        'dentro' => ['2026-12-01', '2026-12-31', '11.50'],
        'antes de empezar' => ['2026-12-11', '2026-12-31', '10.00'],
        'después de terminar' => ['2026-12-01', '2026-12-09', '10.00'],
        'el primer día exacto' => ['2026-12-10', '2026-12-31', '11.50'],
        'el último día exacto' => ['2026-12-01', '2026-12-10', '11.50'],
        'sin fechas' => [null, null, '11.50'],
        'solo desde' => ['2026-12-01', null, '11.50'],
        'solo hasta' => [null, '2026-12-31', '11.50'],
    ]);

    test('ignores an inactive tariff', function () {
        Tariff::factory()->afternoon()->create(['active' => false]);

        expect(priceOf(resourceAt(10), '2026-12-01 16:00', '2026-12-01 17:00'))->toBe('10.00');
    });
});

describe('rounding', function () {
    test('rounds half up a fraction of a cent', function () {
        expect(priceOf(resourceAt(3.33), '2026-12-01 10:00', '2026-12-01 10:30'))->toBe('1.67');
    });

    test('rounds down below half a cent', function () {
        Tariff::factory()->afternoon()->create();

        expect(priceOf(resourceAt(3.33), '2026-12-01 16:00', '2026-12-01 16:30'))->toBe('1.91');
    });

    test('rounds once per slot and the segments add up to the total', function () {
        Tariff::factory()->afternoon()->create(['surcharge_percent' => 50]);

        $price = app(PriceCalculator::class)->priceSlot(
            resourceAt(3.33),
            Carbon::parse('2026-12-01 14:30'),
            Carbon::parse('2026-12-01 15:30'),
        );

        expect($price->decimal())->toBe('4.16')
            ->and(array_map(fn (PriceSegment $segment) => $segment->decimal(), $price->segments))->toBe(['1.67', '2.49']);
    });
});

describe('invalid slots', function () {
    test('rejects a slot with a clear message', function (string $start, string $end, string $message) {
        expect(fn () => priceOf(resourceAt(10), $start, $end))
            ->toThrow(InvalidSlotException::class, __($message));
    })->with([
        'fin igual al inicio' => ['2026-12-01 10:00', '2026-12-01 10:00', 'El fin de la franja debe ser posterior a su inicio.'],
        'fin anterior al inicio' => ['2026-12-01 12:00', '2026-12-01 10:00', 'El fin de la franja debe ser posterior a su inicio.'],
        'cruza la medianoche' => ['2026-12-01 23:00', '2026-12-02 01:00', 'La franja debe empezar y terminar el mismo día.'],
        'termina a las 00:00 del día siguiente' => ['2026-12-01 23:00', '2026-12-02 00:00', 'La franja debe empezar y terminar el mismo día.'],
        'con segundos' => ['2026-12-01 10:00:30', '2026-12-01 11:00', 'La franja debe indicarse en minutos exactos, sin segundos.'],
        'con segundos en el fin' => ['2026-12-01 10:00', '2026-12-01 11:00:30', 'La franja debe indicarse en minutos exactos, sin segundos.'],
    ]);
});

describe('timezone', function () {
    beforeEach(function () {
        Tariff::factory()->afternoon()->create();
        $this->room = resourceAt(10);
    });

    test('the afternoon surcharge is evaluated in Madrid time, in winter', function () {
        // 14:00-15:00 UTC son 15:00-16:00 en Madrid (UTC+1): lleva recargo.
        expect(priceFromUtc($this->room, '2026-12-01 14:00', '2026-12-01 15:00'))->toBe('11.50');
    });

    test('a slot that would have the surcharge in UTC does not have it in Madrid', function () {
        // 19:00-20:00 UTC son 20:00-21:00 en Madrid: ya no lleva recargo.
        expect(priceFromUtc($this->room, '2026-12-01 19:00', '2026-12-01 20:00'))->toBe('10.00');
    });

    test('takes the daylight saving time into account', function () {
        // 13:00-14:00 UTC son 15:00-16:00 en Madrid en verano (UTC+2).
        expect(priceFromUtc($this->room, '2026-07-01 13:00', '2026-07-01 14:00'))->toBe('11.50');
    });

    test('a slot written in Madrid time has the surcharge', function () {
        expect(priceOf($this->room, '2026-12-01 15:00', '2026-12-01 16:00'))->toBe('11.50');
    });

    test('the day of the week is evaluated in Madrid time', function () {
        Tariff::query()->delete();
        Tariff::factory()->weekend()->create();

        // Viernes a las 23:00 UTC ya es sábado a las 00:00 en Madrid.
        expect(priceFromUtc($this->room, '2026-12-04 23:00', '2026-12-04 23:30'))->toBe('6.00');
    });
});

describe('several slots', function () {
    test('the total is the sum of the rounded slots', function () {
        $first = resourceAt(3.33);
        $second = resourceAt(3.33);

        $price = app(PriceCalculator::class)->priceSlots([
            new Slot($first, Carbon::parse('2026-12-01 10:00'), Carbon::parse('2026-12-01 10:30')),
            new Slot($second, Carbon::parse('2026-12-01 10:00'), Carbon::parse('2026-12-01 10:30')),
        ]);

        expect(array_map(fn ($slot) => $slot->decimal(), $price->slots))->toBe(['1.67', '1.67'])
            ->and($price->decimal())->toBe('3.34');
    });

    test('each slot uses the tariffs of its own resource', function () {
        $desk = resourceAt(3);
        $room = resourceAt(12);
        Tariff::factory()->for($desk)->create(['price_per_hour' => 2]);

        $price = app(PriceCalculator::class)->priceSlots([
            new Slot($desk, Carbon::parse('2026-12-01 10:00'), Carbon::parse('2026-12-01 11:00')),
            new Slot($room, Carbon::parse('2026-12-01 10:00'), Carbon::parse('2026-12-01 11:00')),
        ]);

        expect($price->decimal())->toBe('14.00');
    });

    test('loads the tariffs in a single query', function () {
        Tariff::factory()->afternoon()->create();
        $slots = collect(range(1, 5))->map(fn () => new Slot(
            resourceAt(10),
            Carbon::parse('2026-12-01 14:00'),
            Carbon::parse('2026-12-01 16:00'),
        ))->all();

        DB::enableQueryLog();
        app(PriceCalculator::class)->priceSlots($slots);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($queries)->toHaveCount(1);
    });

    test('an empty list costs nothing', function () {
        expect(app(PriceCalculator::class)->priceSlots([])->decimal())->toBe('0.00');
    });
});

test('does not write anything in the database', function () {
    Tariff::factory()->afternoon()->create();
    $room = resourceAt(10);

    DB::enableQueryLog();
    priceOf($room, '2026-12-01 14:00', '2026-12-01 16:00');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->each(fn ($query) => $query->query->toStartWith('select'));
});
