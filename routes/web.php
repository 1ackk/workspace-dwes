<?php

use App\Enums\RoleName;
use App\Http\Controllers\Account\NotificationSettingsController;
use App\Http\Controllers\Accounting\InvoiceController as AccountingInvoiceController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\Manager\ResourceController;
use App\Http\Controllers\Manager\SpaceController;
use App\Http\Controllers\MyBookingController;
use App\Livewire\BookingCalendar;
use App\Models\Booking;
use App\Models\Space;
use Illuminate\Support\Facades\Route;

// Zona pública
Route::view('/', 'home')->name('home');

// Autenticación: un usuario autenticado es redirigido al panel
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');

    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->name('register.store');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// Zona privada: un invitado es redirigido al login
Route::middleware('auth')->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Reservas del usuario con sesión (solo lectura)
    Route::get('my-bookings', [MyBookingController::class, 'index'])->name('my-bookings.index');
    Route::get('my-bookings/{booking}', [MyBookingController::class, 'show'])->name('my-bookings.show');

    // Mi cuenta → Notificaciones (A2): siempre sobre el usuario autenticado, sin id en la ruta
    Route::get('account/notifications', [NotificationSettingsController::class, 'edit'])->name('account.notifications.edit');
    Route::put('account/notifications', [NotificationSettingsController::class, 'update'])->name('account.notifications.update');

    // Reservar (Livewire): hace falta poder crear reservas (BookingPolicy::create, bookings.create)
    Route::livewire('calendar', BookingCalendar::class)
        ->middleware('can:create,'.Booking::class)
        ->name('calendar');

    // Pago simulado: BookingPolicy::confirm decide con el middleware can
    Route::post('my-bookings/{booking}/confirm', [MyBookingController::class, 'confirm'])
        ->middleware('can:confirm,booking')
        ->name('my-bookings.confirm');

    // Detalle de una factura: InvoicePolicy decide con Gate::authorize en el controlador
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');

    // Detalle de una incidencia: IncidentPolicy decide con Gate::authorize en el controlador
    Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
});

// Gestión por rol: otro rol recibe un 403 y un invitado va al login
Route::middleware('auth')->group(function () {
    Route::view('admin', 'admin.index')
        ->middleware('role:'.RoleName::Admin->value)
        ->name('admin.index');

    Route::post('admin/maintenance/{task}', MaintenanceController::class)
        ->whereIn('task', array_keys(MaintenanceController::TASKS))
        ->middleware('role:'.RoleName::Admin->value)
        ->name('admin.maintenance.run');

    // Contabilidad: el grupo filtra por rol y InvoicePolicy decide (viewAny y create)
    Route::middleware('role:'.RoleName::Accountant->value)
        ->prefix('accounting')
        ->name('accounting.')
        ->group(function () {
            Route::get('/', [AccountingInvoiceController::class, 'index'])->name('index');
            Route::post('invoices/generate', [AccountingInvoiceController::class, 'store'])->name('invoices.generate');
        });

    Route::view('reception', 'reception.index')
        ->middleware('role:'.RoleName::Reception->value)
        ->name('reception.index');

    Route::view('manager', 'manager.index')
        ->middleware('role:'.RoleName::SpaceManager->value)
        ->name('manager.index');

    // Recursos: el grupo filtra por rol y la policy decide por espacio
    Route::prefix('manager')
        ->name('manager.')
        ->middleware('role:'.RoleName::SpaceManager->value.'|'.RoleName::Admin->value)
        ->group(function () {
            Route::resource('resources', ResourceController::class)->except(['show', 'destroy']);

            // Espacios: SpacePolicy decide con el middleware can (viewManagement y update)
            Route::get('spaces', [SpaceController::class, 'index'])
                ->middleware('can:viewManagement,'.Space::class)
                ->name('spaces.index');
            Route::get('spaces/{space}/edit', [SpaceController::class, 'edit'])
                ->middleware('can:update,space')
                ->name('spaces.edit');
            Route::put('spaces/{space}', [SpaceController::class, 'update'])
                ->middleware('can:update,space')
                ->name('spaces.update');
        });
});
