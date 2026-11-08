<?php

/**
 * Prueba de concurrencia real: procesos PHP simultáneos contra MySQL.
 *
 * Con SQLite se omite. En PowerShell, desde proyecto/ (ver docs/reservas.md):
 *
 * $env:DB_CONNECTION='mysql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3306'; $env:DB_DATABASE='workspace_test'; $env:DB_USERNAME='root'; $env:DB_PASSWORD=''; php artisan test --group=concurrency; Remove-Item Env:DB_*
 *
 * No usa RefreshDatabase: los datos deben estar confirmados para que los hijos los vean.
 */

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

const CONCURRENCY_DATABASE = 'workspace_test';
const CONCURRENCY_ROUNDS = 5;
const WORKER_TIMEOUT_SECONDS = 30.0;
const START_DELAY_SECONDS = 0.3;

function usesConcurrencyConnection(): bool
{
    return in_array(config('database.default'), ['mysql', 'mariadb', 'pgsql'], true);
}

function assertDedicatedConcurrencyDatabase(): void
{
    $connection = config('database.connections.'.config('database.default'));

    if ($connection['database'] !== CONCURRENCY_DATABASE || ! in_array($connection['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException(sprintf(
            'La prueba de concurrencia borra la base de datos: solo se ejecuta contra "%s" en local, no contra "%s" en "%s".',
            CONCURRENCY_DATABASE,
            $connection['database'],
            $connection['host'],
        ));
    }
}

function migrateConcurrencyDatabaseOnce(): void
{
    static $migrated = false;

    if (! $migrated) {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $migrated = true;
    }
}

function clearConcurrencyData(): void
{
    foreach (['booking_resource', 'bookings', 'resources', 'spaces', 'users'] as $table) {
        DB::table($table)->delete();
    }
}

/**
 * @return array<string, string>
 */
function workerEnvironment(): array
{
    $connection = config('database.connections.'.config('database.default'));

    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => $connection['database'],
        'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
    ];
}

/**
 * Atiende a todos los procesos hasta que se cumpla la condición. Atenderlos es lo que hace
 * que Symfony les entregue lo escrito por stdin.
 *
 * @param  list<Process>  $processes
 */
function pumpProcessesUntil(array $processes, Closure $condition, string $waitingFor): void
{
    $deadline = microtime(true) + WORKER_TIMEOUT_SECONDS;

    while (! $condition()) {
        if (microtime(true) > $deadline) {
            $outputs = array_map(fn (Process $process) => $process->getOutput().$process->getErrorOutput(), $processes);

            foreach ($processes as $process) {
                $process->stop(0);
            }

            throw new RuntimeException("Tiempo agotado esperando {$waitingFor}: ".implode(' | ', $outputs));
        }

        foreach ($processes as $process) {
            $process->isRunning();
        }

        usleep(500);
    }
}

/**
 * Apretón de manos: cuando todos los hijos han arrancado, el padre les da una salida común.
 *
 * @param  list<array<string, mixed>>  $jobs
 * @return list<array{outcome: string, late: bool, message?: string}>
 */
function runWorkers(array $jobs): array
{
    $inputs = [];
    $processes = [];

    foreach ($jobs as $job) {
        $input = new InputStream;
        $process = new Process(
            [PHP_BINARY, __DIR__.'/booking_worker.php', base64_encode(json_encode($job))],
            base_path(),
            workerEnvironment(),
            $input,
            WORKER_TIMEOUT_SECONDS,
        );
        $process->start();

        $inputs[] = $input;
        $processes[] = $process;
    }

    pumpProcessesUntil(
        $processes,
        fn () => collect($processes)->every(fn (Process $process) => str_contains($process->getOutput(), 'READY')),
        'a que los procesos estén listos',
    );

    $startAt = microtime(true) + START_DELAY_SECONDS;

    foreach ($inputs as $input) {
        $input->write($startAt.PHP_EOL);
        $input->close();
    }

    pumpProcessesUntil(
        $processes,
        fn () => collect($processes)->every(fn (Process $process) => ! $process->isRunning()),
        'a que los procesos terminen',
    );

    return array_map(function (Process $process): array {
        if (preg_match('/^RESULT (.+)$/m', $process->getOutput(), $matches) !== 1) {
            throw new RuntimeException('Un proceso no devolvió resultado: '.$process->getOutput().$process->getErrorOutput());
        }

        return json_decode($matches[1], true);
    }, $processes);
}

/**
 * @param  list<App\Models\Resource>  $resources
 * @return array<string, mixed>
 */
function workerJob(string $mode, array $resources, string $start, string $end): array
{
    return [
        'mode' => $mode,
        'user_id' => User::factory()->create()->id,
        'slots' => array_map(fn (Resource $resource) => [
            'resource_id' => $resource->id,
            'start' => $start,
            'end' => $end,
        ], $resources),
    ];
}

/**
 * @return array{0: string, 1: string}
 */
function concurrencySlot(int $round): array
{
    $day = now()->addDays(30)->toDateString();
    $hour = 8 + $round;

    return [sprintf('%s %02d:00:00', $day, $hour), sprintf('%s %02d:00:00', $day, $hour + 1)];
}

/**
 * @param  list<array{outcome: string, late: bool, message?: string}>  $results
 * @return array<string, int>
 */
function outcomesOf(array $results): array
{
    return array_count_values(array_column($results, 'outcome'));
}

/**
 * @param  list<array{outcome: string, late: bool, message?: string}>  $results
 */
function expectNobodyWasLate(array $results): void
{
    expect(array_column($results, 'late'))->not->toContain(true, 'Algún proceso llegó tarde: la salida no fue simultánea.');
}

function bookingsOnSlot(Resource $resource, string $start): int
{
    return DB::table('booking_resource')
        ->where('resource_id', $resource->id)
        ->where('start_at', $start)
        ->count();
}

function usesInnodb(): bool
{
    return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
}

/**
 * Lee el contador de interbloqueos de InnoDB. Si está apagado falla: medir cero no valdría.
 */
function deadlockCount(): int
{
    $metric = DB::selectOne(
        "select `status` as metric_status, `count` as metric_count from information_schema.innodb_metrics where `name` = 'lock_deadlocks'"
    );

    if ($metric === null || strcasecmp($metric->metric_status, 'enabled') !== 0) {
        throw new RuntimeException(
            "El contador lock_deadlocks de InnoDB no está activado. Actívalo en MySQL con: SET GLOBAL innodb_monitor_enable = 'lock_deadlocks';"
        );
    }

    return (int) $metric->metric_count;
}

beforeEach(function () {
    if (! usesConcurrencyConnection()) {
        $this->markTestSkipped('La prueba de concurrencia necesita mysql, mariadb o pgsql (ver docs/reservas.md). Con SQLite lockForUpdate no actúa.');
    }

    assertDedicatedConcurrencyDatabase();
    migrateConcurrencyDatabaseOnce();
    clearConcurrencyData();
});

afterEach(function () {
    if (usesConcurrencyConnection()) {
        assertDedicatedConcurrencyDatabase();
        clearConcurrencyData();
    }
});

test('only one of several simultaneous requests gets the slot', function (int $processes) {
    $resource = Resource::factory()->create();

    foreach (range(1, CONCURRENCY_ROUNDS) as $round) {
        [$start, $end] = concurrencySlot($round);

        $jobs = array_map(fn () => workerJob('service', [$resource], $start, $end), range(1, $processes));
        $results = runWorkers($jobs);

        expectNobodyWasLate($results);
        expect(outcomesOf($results))->toEqual(['created' => 1, 'unavailable' => $processes - 1], json_encode($results))
            ->and(bookingsOnSlot($resource, $start))->toBe(1);
    }

    expect(DB::table('bookings')->count())->toBe(CONCURRENCY_ROUNDS);
})->with([
    '2 procesos' => 2,
    '4 procesos' => 4,
]);

test('two bookings with the resources in opposite order: one wins and there is no deadlock', function () {
    $first = Resource::factory()->create();
    $second = Resource::factory()->create();
    $deadlocksBefore = usesInnodb() ? deadlockCount() : 0;

    foreach (range(1, CONCURRENCY_ROUNDS) as $round) {
        [$start, $end] = concurrencySlot($round);

        $results = runWorkers([
            workerJob('service', [$first, $second], $start, $end),
            workerJob('service', [$second, $first], $start, $end),
        ]);

        expectNobodyWasLate($results);
        expect(outcomesOf($results))->toEqual(['created' => 1, 'unavailable' => 1], json_encode($results))
            ->and(bookingsOnSlot($first, $start))->toBe(1)
            ->and(bookingsOnSlot($second, $start))->toBe(1);
    }

    if (usesInnodb()) {
        expect(deadlockCount())->toBe($deadlocksBefore);
    }
});

test('two simultaneous cancellations of the same booking: one wins and the other is told it is already cancelled', function () {
    $resource = Resource::factory()->create();

    foreach (range(1, CONCURRENCY_ROUNDS) as $round) {
        [$start, $end] = concurrencySlot($round);
        $booking = Booking::factory()->confirmed()
            ->withSlot($resource, Carbon::parse($start), Carbon::parse($end))
            ->create();
        $job = ['mode' => 'cancel', 'user_id' => $booking->user_id, 'slots' => [], 'booking_id' => $booking->id];

        $results = runWorkers([$job, $job]);

        expectNobodyWasLate($results);
        expect(outcomesOf($results))->toEqual(['cancelled' => 1, 'not_cancellable' => 1], json_encode($results))
            ->and(collect($results)->firstWhere('outcome', 'not_cancellable')['message'])->toBe(__('La reserva ya está cancelada.'))
            ->and($booking->fresh()->status)->toBe(BookingStatus::Cancelled)
            ->and($booking->fresh()->cancelled_at)->not->toBeNull();
    }
});

test('negative control: checking and inserting without a lock does produce a double booking', function () {
    $resource = Resource::factory()->create();
    [$start, $end] = concurrencySlot(1);

    $results = runWorkers(array_map(fn () => workerJob('naive', [$resource], $start, $end), range(1, 4)));

    expectNobodyWasLate($results);
    expect(outcomesOf($results)['created'] ?? 0)->toBeGreaterThan(1, json_encode($results))
        ->and(bookingsOnSlot($resource, $start))->toBeGreaterThan(1);
});

test('negative control: locking in opposite order makes the deadlock counter go up', function () {
    if (! usesInnodb()) {
        $this->markTestSkipped('El contador lock_deadlocks solo existe en InnoDB.');
    }

    $first = Resource::factory()->create();
    $second = Resource::factory()->create();
    [$start, $end] = concurrencySlot(1);
    $deadlocksBefore = deadlockCount();

    $results = runWorkers([
        workerJob('unordered', [$first, $second], $start, $end),
        workerJob('unordered', [$second, $first], $start, $end),
    ]);

    expectNobodyWasLate($results);
    expect(outcomesOf($results))->toEqual(['deadlock' => 1, 'locked' => 1], json_encode($results))
        ->and(deadlockCount())->toBeGreaterThan($deadlocksBefore);
});
