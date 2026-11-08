<?php

use App\Enums\RoleName;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Manager\ResourceController;
use App\Http\Controllers\MyBookingController;
use Illuminate\Support\Facades\Route;

// Zona pública
Route::view('/', 'home')->name('home');

// Solo invitados: un usuario autenticado es redirigido al panel
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);

    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store']);
});

// Zona privada: un invitado es redirigido al login
Route::middleware('auth')->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // Páginas provisionales por rol de gestión: otro rol recibe un 403
    Route::view('admin', 'admin.index')
        ->middleware('role:'.RoleName::Admin->value)
        ->name('admin.index');

    Route::view('accounting', 'accounting.index')
        ->middleware('role:'.RoleName::Accountant->value)
        ->name('accounting.index');

    Route::view('reception', 'reception.index')
        ->middleware('role:'.RoleName::Reception->value)
        ->name('reception.index');

    Route::view('manager', 'manager.index')
        ->middleware('role:'.RoleName::SpaceManager->value)
        ->name('manager.index');

    // Reservas del usuario con sesión (solo lectura)
    Route::get('my-bookings', [MyBookingController::class, 'index'])->name('my-bookings.index');
    Route::get('my-bookings/{booking}', [MyBookingController::class, 'show'])->name('my-bookings.show');

    // Gestión de recursos: el grupo filtra por rol y la policy decide por espacio
    Route::prefix('manager')
        ->name('manager.')
        ->middleware('role:'.RoleName::SpaceManager->value.'|'.RoleName::Admin->value)
        ->group(function () {
            Route::resource('resources', ResourceController::class)->except(['show', 'destroy']);
        });
});
