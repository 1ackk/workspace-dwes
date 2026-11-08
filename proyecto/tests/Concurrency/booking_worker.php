<?php

/**
 * Proceso hijo de BookingConcurrencyTest. No forma parte de la aplicación.
 *
 * Arranca Laravel, avisa con READY, espera por stdin la hora de salida común, ejecuta su
 * modo y escribe RESULT seguido de un JSON. Los modos "naive" y "unordered" existen solo
 * aquí, para demostrar que la prueba es capaz de detectar los fallos que busca.
 */

use App\Enums\BookingStatus;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Pricing\Slot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const NAIVE_PAUSE_MICROSECONDS = 300_000;

/**
 * @param  array<string, mixed>  $result
 */
function respond(array $result): never
{
    echo 'RESULT '.json_encode($result).PHP_EOL;

    exit(0);
}

/**
 * @param  list<Slot>  $slots
 */
function createWithService(User $user, array $slots): string
{
    app(BookingService::class)->createPending($user, $slots);

    return 'created';
}

/**
 * Comprueba y luego inserta sin bloqueo ni transacción: el fallo que evita BookingService.
 *
 * @param  list<Slot>  $slots
 */
function createNaively(User $user, array $slots): string
{
    foreach ($slots as $slot) {
        if (! Resource::query()->whereKey($slot->resource->id)->available($slot->start, $slot->end)->exists()) {
            throw new SlotUnavailableException($slot);
        }
    }

    usleep(NAIVE_PAUSE_MICROSECONDS);

    $booking = Booking::create([
        'user_id' => $user->id,
        'date' => $slots[0]->start->toDateString(),
        'status' => BookingStatus::PendingPayment,
        'expires_at' => now()->addMinutes(15),
        'total' => 0,
    ]);

    foreach ($slots as $slot) {
        $booking->resources()->attach($slot->resource->id, [
            'start_at' => $slot->start,
            'end_at' => $slot->end,
            'precio_final' => 0,
        ]);
    }

    return 'created';
}

/**
 * Bloquea los recursos uno a uno en el orden recibido, para provocar un interbloqueo.
 *
 * @param  list<Slot>  $slots
 */
function lockUnordered(array $slots): string
{
    DB::transaction(function () use ($slots): void {
        foreach ($slots as $slot) {
            Resource::query()->whereKey($slot->resource->id)->lockForUpdate()->first();
            usleep(NAIVE_PAUSE_MICROSECONDS);
        }
    });

    return 'locked';
}

$job = json_decode(base64_decode($argv[1]), true);

if (config('database.connections.'.config('database.default').'.database') !== 'workspace_test') {
    respond(['outcome' => 'error', 'message' => 'Este proceso solo se ejecuta contra workspace_test.', 'late' => false]);
}

$user = User::query()->findOrFail($job['user_id']);
$slots = array_map(fn (array $slot) => new Slot(
    Resource::query()->findOrFail($slot['resource_id']),
    Carbon::parse($slot['start']),
    Carbon::parse($slot['end']),
), $job['slots']);

echo 'READY'.PHP_EOL;

$startAt = (float) fgets(STDIN);
$late = microtime(true) >= $startAt;

while (microtime(true) < $startAt) {
    // Espera activa: dormir daría una salida menos simultánea.
}

try {
    $outcome = match ($job['mode']) {
        'service' => createWithService($user, $slots),
        'naive' => createNaively($user, $slots),
        'unordered' => lockUnordered($slots),
    };

    respond(['outcome' => $outcome, 'late' => $late]);
} catch (SlotUnavailableException) {
    respond(['outcome' => 'unavailable', 'late' => $late]);
} catch (QueryException $exception) {
    $isDeadlock = str_contains(strtolower($exception->getMessage()), 'deadlock');

    respond(['outcome' => $isDeadlock ? 'deadlock' : 'error', 'message' => $exception->getMessage(), 'late' => $late]);
} catch (Throwable $exception) {
    respond(['outcome' => 'error', 'message' => $exception::class.': '.$exception->getMessage(), 'late' => $late]);
}
