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

        Artisan::call($command);

        return to_route('admin.index')->with('status', trim(Artisan::output()));
    }
}
