<?php

use App\Enums\ResourceType;
use App\Enums\RoleName;
use App\Models\Resource;
use App\Models\Space;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);

    $this->manager = userWithRole(RoleName::SpaceManager);
    $this->space = Space::factory()->create(['manager_id' => $this->manager->id, 'name' => 'Espacio Propio']);
    $this->otherSpace = Space::factory()->create(['name' => 'Espacio Ajeno']);
    $this->resource = Resource::factory()->for($this->space)->create(['name' => 'Puesto Propio']);
    $this->otherResource = Resource::factory()->for($this->otherSpace)->create(['name' => 'Puesto Ajeno']);
});

function resourceData(array $overrides = []): array
{
    return [
        'name' => 'Sala Nueva',
        'type' => ResourceType::Room->value,
        'capacity' => 6,
        'hourly_rate' => '12.50',
        ...$overrides,
    ];
}

describe('list and forms', function () {
    test('a manager lists only the resources of their spaces', function () {
        $this->actingAs($this->manager)
            ->get(route('manager.resources.index'))
            ->assertOk()
            ->assertSee('Puesto Propio')
            ->assertDontSee('Puesto Ajeno');
    });

    test('an administrator lists every resource', function () {
        $this->actingAs(userWithRole(RoleName::Admin))
            ->get(route('manager.resources.index'))
            ->assertOk()
            ->assertSee('Puesto Propio')
            ->assertSee('Puesto Ajeno');
    });

    test('the create form offers only the spaces of the manager', function () {
        $this->actingAs($this->manager)
            ->get(route('manager.resources.create'))
            ->assertOk()
            ->assertSee('Espacio Propio')
            ->assertDontSee('Espacio Ajeno');
    });

    test('the create form offers every space to the administrator', function () {
        $this->actingAs(userWithRole(RoleName::Admin))
            ->get(route('manager.resources.create'))
            ->assertOk()
            ->assertSee('Espacio Propio')
            ->assertSee('Espacio Ajeno');
    });

    test('the edit form shows the current values', function () {
        $this->actingAs($this->manager)
            ->get(route('manager.resources.edit', $this->resource))
            ->assertOk()
            ->assertSee('value="Puesto Propio"', false);
    });

    test('the manager page links to the list', function () {
        $this->actingAs($this->manager)
            ->get(route('manager.index'))
            ->assertSee(route('manager.resources.index'));
    });
});

describe('create', function () {
    test('a manager creates a resource in their space', function () {
        $this->actingAs($this->manager)
            ->post(route('manager.resources.store'), resourceData(['space_id' => $this->space->id]))
            ->assertRedirect(route('manager.resources.index'));

        $this->assertDatabaseHas('resources', [
            'space_id' => $this->space->id,
            'name' => 'Sala Nueva',
            'type' => ResourceType::Room->value,
            'capacity' => 6,
            'hourly_rate' => 12.50,
        ]);
    });

    test('a manager cannot create in another space', function () {
        $this->actingAs($this->manager)
            ->post(route('manager.resources.store'), resourceData(['space_id' => $this->otherSpace->id]))
            ->assertForbidden();

        $this->assertDatabaseMissing('resources', ['name' => 'Sala Nueva']);
    });

    test('an administrator creates in any space', function () {
        $this->actingAs(userWithRole(RoleName::Admin))
            ->post(route('manager.resources.store'), resourceData(['space_id' => $this->otherSpace->id]))
            ->assertRedirect(route('manager.resources.index'));

        $this->assertDatabaseHas('resources', ['space_id' => $this->otherSpace->id, 'name' => 'Sala Nueva']);
    });

    test('invalid data shows the errors', function () {
        $this->actingAs($this->manager)
            ->from(route('manager.resources.create'))
            ->followingRedirects()
            ->post(route('manager.resources.store'), resourceData([
                'space_id' => $this->space->id,
                'capacity' => -1,
                'hourly_rate' => -5,
            ]))
            ->assertOk()
            ->assertSee(__('La capacidad no puede ser negativa.'))
            ->assertSee(__('La tarifa por hora no puede ser negativa.'))
            ->assertSee('value="Sala Nueva"', false);

        $this->assertDatabaseMissing('resources', ['name' => 'Sala Nueva']);
    });

    test('rejects each invalid field', function (string $field, mixed $value) {
        $this->actingAs($this->manager)
            ->post(route('manager.resources.store'), resourceData([
                'space_id' => $this->space->id,
                $field => $value,
            ]))
            ->assertSessionHasErrors($field);
    })->with([
        'sin nombre' => ['name', ''],
        'tipo fuera del enum' => ['type', 'garage'],
        'capacidad decimal' => ['capacity', 1.5],
        'capacidad negativa' => ['capacity', -1],
        'tarifa no numérica' => ['hourly_rate', 'gratis'],
        'tarifa negativa' => ['hourly_rate', -0.01],
        'tarifa con tres decimales' => ['hourly_rate', 1.234],
    ]);

    test('requires a space that exists', function (mixed $spaceId) {
        $this->actingAs($this->manager)
            ->post(route('manager.resources.store'), resourceData(['space_id' => $spaceId]))
            ->assertSessionHasErrors('space_id');
    })->with(['vacío' => '', 'inexistente' => 999999]);

    test('rejects a name already used in the space', function () {
        $this->actingAs($this->manager)
            ->post(route('manager.resources.store'), resourceData([
                'space_id' => $this->space->id,
                'name' => 'Puesto Propio',
            ]))
            ->assertSessionHasErrors([
                'name' => __('Ya existe un recurso con este nombre en el espacio.'),
            ]);
    });

    test('allows a name already used in another space', function () {
        $this->actingAs($this->manager)
            ->post(route('manager.resources.store'), resourceData([
                'space_id' => $this->space->id,
                'name' => 'Puesto Ajeno',
            ]))
            ->assertSessionHasNoErrors();

        expect(Resource::where('name', 'Puesto Ajeno')->count())->toBe(2);
    });
});

describe('edit', function () {
    test('a manager edits a resource of their space', function () {
        $this->actingAs($this->manager)
            ->put(route('manager.resources.update', $this->resource), resourceData(['space_id' => $this->space->id]))
            ->assertRedirect(route('manager.resources.index'));

        expect($this->resource->fresh()->name)->toBe('Sala Nueva');
    });

    test('can keep the same name', function () {
        $this->actingAs($this->manager)
            ->put(route('manager.resources.update', $this->resource), resourceData([
                'space_id' => $this->space->id,
                'name' => 'Puesto Propio',
            ]))
            ->assertSessionHasNoErrors();
    });

    test('a manager cannot open or update a resource of another space', function () {
        $this->actingAs($this->manager)
            ->get(route('manager.resources.edit', $this->otherResource))
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->put(route('manager.resources.update', $this->otherResource), resourceData(['space_id' => $this->space->id]))
            ->assertForbidden();

        expect($this->otherResource->fresh()->name)->toBe('Puesto Ajeno');
    });

    test('a manager cannot move their resource to another space', function () {
        $this->actingAs($this->manager)
            ->put(route('manager.resources.update', $this->resource), resourceData(['space_id' => $this->otherSpace->id]))
            ->assertForbidden();

        expect($this->resource->fresh()->space_id)->toBe($this->space->id);
    });

    test('an administrator edits any resource', function () {
        $this->actingAs(userWithRole(RoleName::Admin))
            ->put(route('manager.resources.update', $this->otherResource), resourceData(['space_id' => $this->otherSpace->id]))
            ->assertRedirect(route('manager.resources.index'));

        expect($this->otherResource->fresh()->name)->toBe('Sala Nueva');
    });

    test('invalid data shows the errors', function () {
        $this->actingAs($this->manager)
            ->from(route('manager.resources.edit', $this->resource))
            ->followingRedirects()
            ->put(route('manager.resources.update', $this->resource), resourceData([
                'space_id' => $this->space->id,
                'type' => 'garage',
            ]))
            ->assertOk()
            ->assertSee(__('El tipo no es válido.'));
    });
});

describe('access', function () {
    dataset('roles without access', [
        'usuario' => [RoleName::Member],
        'recepción' => [RoleName::Reception],
        'contable' => [RoleName::Accountant],
    ]);

    test('other roles get 403 in the whole flow', function (RoleName $role) {
        $user = userWithRole($role);
        $data = resourceData(['space_id' => $this->space->id]);

        $this->actingAs($user)->get(route('manager.resources.index'))->assertForbidden();
        $this->actingAs($user)->get(route('manager.resources.create'))->assertForbidden();
        $this->actingAs($user)->post(route('manager.resources.store'), $data)->assertForbidden();
        $this->actingAs($user)->get(route('manager.resources.edit', $this->resource))->assertForbidden();
        $this->actingAs($user)->put(route('manager.resources.update', $this->resource), $data)->assertForbidden();

        $this->assertDatabaseMissing('resources', ['name' => 'Sala Nueva']);
    })->with('roles without access');

    test('a guest goes to the login in the whole flow', function () {
        $data = resourceData(['space_id' => $this->space->id]);

        $this->get(route('manager.resources.index'))->assertRedirect(route('login'));
        $this->get(route('manager.resources.create'))->assertRedirect(route('login'));
        $this->post(route('manager.resources.store'), $data)->assertRedirect(route('login'));
        $this->get(route('manager.resources.edit', $this->resource))->assertRedirect(route('login'));
        $this->put(route('manager.resources.update', $this->resource), $data)->assertRedirect(route('login'));
    });
});
