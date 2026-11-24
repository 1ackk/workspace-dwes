<?php

use App\Http\Controllers\Accounting\InvoiceController;
use App\Http\Controllers\Admin\MaintenanceController;

/**
 * Recuento de los mínimos del proyecto buscando en el código, para que una clase de más o de menos
 * se note en los tests. El listener y el job de Telegram son la ampliación A2, aparte del núcleo.
 */
function phpFilesIn(string $directory): array
{
    return glob(app_path($directory).'/*.php') ?: [];
}

function filesContaining(string $directory, string $needle): array
{
    return array_values(array_filter(phpFilesIn($directory), fn (string $file) => str_contains((string) file_get_contents($file), $needle)));
}

test('5 events', function () {
    expect(phpFilesIn('Events'))->toHaveCount(5);
});

test('5 listeners of the core plus 1 of A2 (Telegram)', function () {
    $listeners = array_map('basename', phpFilesIn('Listeners'));

    expect($listeners)->toHaveCount(6)
        ->and(array_values(array_diff($listeners, ['SendBookingConfirmedTelegram.php'])))->toHaveCount(5);
});

test('5 jobs of the core (3 queued) plus 1 of A2 (Telegram, queued)', function () {
    $jobs = array_map('basename', filesContaining('Jobs', 'implements ShouldQueue'));
    $queued = array_map('basename', filesContaining('Jobs', 'onQueue(self::QUEUE)'));

    expect($jobs)->toHaveCount(6)
        ->and(array_values(array_diff($jobs, ['SendBookingConfirmedTelegramJob.php'])))->toHaveCount(5)
        ->and(array_values(array_diff($queued, ['SendBookingConfirmedTelegramJob.php'])))->toHaveCount(3);
});

test('5 mails', function () {
    expect(filesContaining('Mail', 'extends Mailable'))->toHaveCount(5);
});

test('7 commands, 3 of them called from the code', function () {
    expect(filesContaining('Console/Commands', 'protected $signature'))->toHaveCount(7)
        ->and(MaintenanceController::TASKS)->toHaveCount(2)
        ->and(InvoiceController::COMMAND)->toBe('invoices:generate');
});

test('7 scopes', function () {
    $scopes = 0;

    foreach (phpFilesIn('Models') as $file) {
        $scopes += substr_count((string) file_get_contents($file), '#[Scope]');
    }

    expect($scopes)->toBe(7);
});

test('1 Livewire class, 0 CRUD', function () {
    expect(array_map('basename', phpFilesIn('Livewire')))->toBe(['BookingCalendar.php']);
});

test('composer dev listens to the mail queue, where every mail and notice goes', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $dev = implode(' ', $composer['scripts']['dev']);

    expect($dev)->toContain('queue:listen --queue=mail,default');
});
