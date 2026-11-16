<?php

use App\Models\Booking;
use App\Models\Resource;
use App\Models\Space;
use Illuminate\Support\Carbon;

const OCCUPANCY_HEADERS = ['Espacio', 'Recurso', 'Horas reservadas', 'Horas disponibles', 'Ocupación'];

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');

    $this->space = Space::factory()->create(['name' => 'Planta A']);
    $this->resource = Resource::factory()->for($this->space)->create(['name' => 'Puesto A']);
});

function bookReportSlot(Resource $resource, string $start, string $end): Booking
{
    return Booking::factory()->confirmed()
        ->withSlot($resource, Carbon::parse($start), Carbon::parse($end))
        ->create();
}

test('shows the hours and the occupancy of each resource', function () {
    bookReportSlot($this->resource, '2026-12-10 10:00', '2026-12-10 13:00');

    $this->artisan('occupancy:report', ['from' => '2026-12-10', 'to' => '2026-12-10'])
        ->expectsTable(OCCUPANCY_HEADERS, [['Planta A', 'Puesto A', '3.00', '12.00', '25.00 %']])
        ->assertExitCode(0);
});

test('both days of the period are included', function () {
    bookReportSlot($this->resource, '2026-12-11 18:00', '2026-12-11 20:00');

    $this->artisan('occupancy:report', ['from' => '2026-12-10', 'to' => '2026-12-11'])
        ->expectsTable(OCCUPANCY_HEADERS, [['Planta A', 'Puesto A', '2.00', '24.00', '8.33 %']])
        ->assertExitCode(0);
});

test('--space filters by space', function () {
    Resource::factory()->for(Space::factory()->create(['name' => 'Planta B']))->create(['name' => 'Puesto B']);

    $this->artisan('occupancy:report', ['from' => '2026-12-10', 'to' => '2026-12-10', '--space' => (string) $this->space->id])
        ->expectsTable(OCCUPANCY_HEADERS, [['Planta A', 'Puesto A', '0.00', '12.00', '0.00 %']])
        ->assertExitCode(0);
});

test('retired resources are included', function () {
    $this->resource->update(['active' => false]);

    $this->artisan('occupancy:report', ['from' => '2026-12-10', 'to' => '2026-12-10'])
        ->expectsTable(OCCUPANCY_HEADERS, [['Planta A', 'Puesto A', '0.00', '12.00', '0.00 %']])
        ->assertExitCode(0);
});

test('an invalid date ends with an error and code 1', function (string $from, string $to, string $wrong) {
    $this->artisan('occupancy:report', ['from' => $from, 'to' => $to])
        ->expectsOutput("Fecha no válida: \"{$wrong}\". Usa el formato AAAA-MM-DD.")
        ->assertExitCode(1);
})->with([
    'from mal escrito' => ['ayer', '2026-12-10', 'ayer'],
    'from imposible' => ['2026-02-31', '2026-12-10', '2026-02-31'],
    'to mal escrito' => ['2026-12-10', 'mañana', 'mañana'],
    'to imposible' => ['2026-12-10', '2026-13-01', '2026-13-01'],
]);

test('an end before the start ends with an error and code 1', function () {
    $this->artisan('occupancy:report', ['from' => '2026-12-11', 'to' => '2026-12-10'])
        ->expectsOutput('La fecha final no puede ser anterior a la inicial.')
        ->assertExitCode(1);
});

test('a space that does not exist ends with an error and code 1', function (string $space) {
    $this->artisan('occupancy:report', ['from' => '2026-12-10', 'to' => '2026-12-10', '--space' => $space])
        ->expectsOutput("El espacio \"{$space}\" no existe.")
        ->assertExitCode(1);
})->with(['999999', 'abc', '1abc', '']);

test('a space without resources says so and ends with 0', function () {
    $empty = Space::factory()->create();

    $this->artisan('occupancy:report', ['from' => '2026-12-10', 'to' => '2026-12-10', '--space' => (string) $empty->id])
        ->expectsOutput('No hay recursos que mostrar.')
        ->assertExitCode(0);
});

test('without any resource it says so and ends with 0', function () {
    $this->resource->delete();

    $this->artisan('occupancy:report', ['from' => '2026-12-10', 'to' => '2026-12-10'])
        ->expectsOutput('No hay recursos que mostrar.')
        ->assertExitCode(0);
});
