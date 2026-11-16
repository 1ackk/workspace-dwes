<?php

use App\Enums\RoleName;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\InvoicePolicy;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);
});

function invoiceOwnedBy(?User $owner = null): Invoice
{
    return Invoice::factory()->for($owner ?? User::factory()->create(), 'user')->create();
}

test('the policy is registered', function () {
    expect(Gate::getPolicyFor(Invoice::class))->toBeInstanceOf(InvoicePolicy::class);
});

test('viewAny and create depend only on the permission', function (RoleName $role, bool $expected) {
    $user = userWithRole($role);

    expect($user->can('viewAny', Invoice::class))->toBe($expected)
        ->and($user->can('create', Invoice::class))->toBe($expected);
})->with([
    'usuario' => [RoleName::Member, false],
    'recepción' => [RoleName::Reception, false],
    'responsable' => [RoleName::SpaceManager, false],
    'contable' => [RoleName::Accountant, true],
    'administrador' => [RoleName::Admin, true],
]);

test('applies each role to own and foreign invoices', function (string $ability, RoleName $role, bool $own, bool $foreign) {
    $user = userWithRole($role);

    expect($user->can($ability, invoiceOwnedBy($user)))->toBe($own, 'own')
        ->and($user->can($ability, invoiceOwnedBy()))->toBe($foreign, 'foreign');
})->with([
    'view usuario' => ['view', RoleName::Member, true, false],
    'view recepción' => ['view', RoleName::Reception, false, false],
    'view responsable' => ['view', RoleName::SpaceManager, false, false],
    'view contable' => ['view', RoleName::Accountant, true, true],
    'view administrador' => ['view', RoleName::Admin, true, true],
    'void usuario' => ['void', RoleName::Member, false, false],
    'void recepción' => ['void', RoleName::Reception, false, false],
    'void responsable' => ['void', RoleName::SpaceManager, false, false],
    'void contable' => ['void', RoleName::Accountant, true, true],
    'void administrador' => ['void', RoleName::Admin, true, true],
]);

test('no role can ever delete an invoice, the administrator included', function (RoleName $role) {
    $user = userWithRole($role);

    foreach ([invoiceOwnedBy($user), invoiceOwnedBy()] as $invoice) {
        expect($user->can('delete', $invoice))->toBeFalse()
            ->and($user->can('forceDelete', $invoice))->toBeFalse()
            ->and(Gate::forUser($user)->denies('delete', $invoice))->toBeTrue();
    }
})->with(RoleName::cases());

test('a user with the member role in addition to another one sees their own invoices', function () {
    $user = userWithRole(RoleName::Reception)->assignRole(RoleName::Member);

    expect($user->can('view', invoiceOwnedBy($user)))->toBeTrue()
        ->and($user->can('view', invoiceOwnedBy()))->toBeFalse();
});

test('a guest can do nothing', function () {
    $invoice = invoiceOwnedBy();

    expect(Gate::forUser(null)->allows('viewAny', Invoice::class))->toBeFalse()
        ->and(Gate::forUser(null)->allows('create', Invoice::class))->toBeFalse();

    foreach (['view', 'void', 'delete'] as $ability) {
        expect(Gate::forUser(null)->allows($ability, $invoice))->toBeFalse($ability);
    }
});

test('void decides who, never when: the invoice state gives the same answer', function (string $state) {
    $invoice = Invoice::factory()->{$state}()->create();

    expect(userWithRole(RoleName::Accountant)->can('void', $invoice))->toBeTrue()
        ->and(userWithRole(RoleName::Admin)->can('void', $invoice))->toBeTrue()
        ->and(userWithRole(RoleName::Member)->can('void', $invoice))->toBeFalse();
})->with(['void', 'paid']);
