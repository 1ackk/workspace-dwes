<?php

use App\Enums\RoleName;
use App\Models\Incident;
use App\Models\Resource;
use App\Models\Space;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);

    $this->manager = userWithRole(RoleName::SpaceManager);
    $this->otherManager = userWithRole(RoleName::SpaceManager);

    $space = Space::factory()->create(['manager_id' => $this->manager->id, 'name' => 'Planta Baja']);
    Space::factory()->create(['manager_id' => $this->otherManager->id, 'name' => 'Planta Alta']);

    $this->author = userWithRole(RoleName::Member);
    $this->incident = Incident::factory()->create([
        'user_id' => $this->author->id,
        'resource_id' => Resource::factory()->for($space)->create(['name' => 'Proyector'])->id,
        'title' => 'El proyector no enciende',
        'description' => 'Se apaga a los dos minutos.',
    ]);
});

describe('allowed', function () {
    test('the author sees the incident', function () {
        $this->actingAs($this->author)
            ->get(route('incidents.show', $this->incident))
            ->assertOk()
            ->assertSee('El proyector no enciende')
            ->assertSee('Se apaga a los dos minutos.')
            ->assertSee('Proyector')
            ->assertSee('Planta Baja')
            ->assertSee('Abierta');
    });

    test('the manager of the space of the resource sees the incident', function () {
        $this->actingAs($this->manager)
            ->get(route('incidents.show', $this->incident))
            ->assertOk()
            ->assertSee('El proyector no enciende');
    });

    test('reception and the administrator see any incident', function (RoleName $role) {
        $this->actingAs(userWithRole($role))
            ->get(route('incidents.show', $this->incident))
            ->assertOk();
    })->with([
        'recepción' => RoleName::Reception,
        'administrador' => RoleName::Admin,
    ]);

    test('a manager sees an incident they opened in another space', function () {
        $this->incident->update(['user_id' => $this->otherManager->id]);

        $this->actingAs($this->otherManager)
            ->get(route('incidents.show', $this->incident))
            ->assertOk();
    });
});

describe('denied', function () {
    test('the manager of another space cannot read the incident', function () {
        $this->actingAs($this->otherManager)
            ->get(route('incidents.show', $this->incident))
            ->assertForbidden()
            ->assertDontSee('El proyector no enciende');
    });

    test('another member cannot read the incident', function () {
        $this->actingAs(userWithRole(RoleName::Member))
            ->get(route('incidents.show', $this->incident))
            ->assertForbidden();
    });

    test('the accountant has no access to incidents', function () {
        $this->actingAs(userWithRole(RoleName::Accountant))
            ->get(route('incidents.show', $this->incident))
            ->assertForbidden();
    });

    test('a guest goes to the login', function () {
        $this->get(route('incidents.show', $this->incident))->assertRedirect(route('login'));
    });

    test('a missing incident returns 404', function () {
        $this->actingAs($this->author)->get(route('incidents.show', 999999))->assertNotFound();
    });
});
