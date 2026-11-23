<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;

class MaintenanceController extends Controller
{
    /**
     * Tareas del panel y su comando. El nombre del comando nunca viene del usuario.
     */
    public const array TASKS = [
        'release-unpaid' => 'bookings:release-unpaid',
        'complete-past' => 'bookings:complete-past',
    ];

    public function __invoke(string $task): RedirectResponse
    {
        $command = self::TASKS[$task] ?? abort(404);

        $exitCode = Artisan::call($command);
        $output = trim(Artisan::output());

        // Cualquier código distinto de 0 (Command::SUCCESS) es un fallo: no se presenta como éxito.
        if ($exitCode !== 0) {
            $message = __('La tarea no se ha completado (código de salida :code).', ['code' => $exitCode]);

            return to_route('admin.index')->with('error', $output === '' ? $message : "{$message} {$output}");
        }

        return to_route('admin.index')->with('status', $output);
    }
}
