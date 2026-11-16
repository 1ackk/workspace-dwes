<?php

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Sin RefreshDatabase: los procesos hijos deben ver datos ya confirmados.
pest()->extend(TestCase::class)
    ->group('concurrency')
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function userWithRole(RoleName $role): User
{
    return User::factory()->create()->assignRole($role);
}

/**
 * Quita del SQL lo que cambia según el motor: las comillas de los identificadores
 * (" en SQLite, ` en MySQL) y el " for update" final, que SQLite no escribe.
 */
function withoutEngineSyntax(string $sql): string
{
    return (string) preg_replace('/ for update$/', '', str_replace(['`', '"'], '', $sql));
}
