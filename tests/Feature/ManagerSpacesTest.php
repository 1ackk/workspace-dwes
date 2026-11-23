<?php

use App\Enums\RoleName;
use App\Models\Space;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);

    $this->manager = userWithRole(RoleName::SpaceManager);
    $this->space = Space::factory()->create([
        'manager_id' => $this->manager->id,
        'name' => 'Espacio Propio',
        'cancellation_notice_hours' => 24,
    ]);
    $this->otherSpace = Space::factory()->create([
        'manager_id' => userWithRole(RoleName::SpaceManager)->id,
        'name' => 'Espacio Ajeno',
        'cancellation_notice_hours' => 24,
    ]);
});

function spaceData(array $overrides = []): array
{
    return [
        'name' => 'Espacio Renovado',
        'description' => 'Planta con luz natural.',
        'location' => 'Calle Nueva 2',
        'cancellation_notice_hours' => 48,
        ...$overrides,
    ];
}

describe('list', function () {
    test('a manager lists only their spaces', function () {
        $this->actingAs($this->manager)
            ->get(route('manager.spaces.index'))
            ->assertOk()
            ->assertSee('Espacio Propio')
            ->assertSee(route('manager.spaces.edit', $this->space))
            ->assertDontSee('Espacio Ajeno');
    });

    test('an administrator lists every space', function () {
        $this->actingAs(userWithRole(RoleName::Admin))
            ->get(route('manager.spaces.index'))
            ->assertOk()
            ->assertSee('Espacio Propio')
            ->assertSee('Espacio Ajeno');
    });

    test('the manager and admin pages link to the spaces', function (RoleName $role, string $page) {
        $this->actingAs(userWithRole($role))
            ->get(route($page))
            ->assertOk()
            ->assertSee(route('manager.spaces.index'));
    })->with([
        'responsable' => [RoleName::SpaceManager, 'manager.index'],
        'administrador' => [RoleName::Admin, 'admin.index'],
    ]);
});

describe('allowed', function () {
    test('a manager opens the form of their space', function () {
        $this->actingAs($this->manager)
            ->get(route('manager.spaces.edit', $this->space))
            ->assertOk()
            ->assertSee('value="Espacio Propio"', false)
            ->assertSee(route('manager.spaces.update', $this->space));
    });

    test('a manager updates their space', function () {
        $this->actingAs($this->manager)
            ->put(route('manager.spaces.update', $this->space), spaceData())
            ->assertRedirect(route('manager.spaces.index'))
            ->assertSessionHas('status', __('Espacio actualizado.'));

        $this->space->refresh();

        expect($this->space->name)->toBe('Espacio Renovado')
            ->and($this->space->description)->toBe('Planta con luz natural.')
            ->and($this->space->location)->toBe('Calle Nueva 2')
            ->and($this->space->cancellation_notice_hours)->toBe(48);
    });

    test('an administrator updates any space', function () {
        $this->actingAs(userWithRole(RoleName::Admin))
            ->put(route('manager.spaces.update', $this->otherSpace), spaceData())
            ->assertRedirect(route('manager.spaces.index'));

        expect($this->otherSpace->fresh()->name)->toBe('Espacio Renovado');
    });

    test('the update never changes the manager or the opening hours', function () {
        $opensAt = $this->space->opens_at;

        $this->actingAs($this->manager)
            ->put(route('manager.spaces.update', $this->space), spaceData([
                'manager_id' => $this->otherSpace->manager_id,
                'opens_at' => '06:00:00',
            ]))
            ->assertRedirect();

        $this->space->refresh();

        expect($this->space->manager_id)->toBe($this->manager->id)
            ->and($this->space->opens_at)->toBe($opensAt);
    });
});

describe('denied', function () {
    test('a manager cannot open the form of another space', function () {
        $this->actingAs($this->manager)
            ->get(route('manager.spaces.edit', $this->otherSpace))
            ->assertForbidden();
    });

    test('a manager cannot update another space and nothing changes', function () {
        $this->actingAs($this->manager)
            ->put(route('manager.spaces.update', $this->otherSpace), spaceData())
            ->assertForbidden();

        expect($this->otherSpace->fresh()->name)->toBe('Espacio Ajeno');
    });

    test('other roles receive a 403', function (RoleName $role) {
        $user = userWithRole($role);

        $this->actingAs($user)->get(route('manager.spaces.index'))->assertForbidden();
        $this->actingAs($user)->get(route('manager.spaces.edit', $this->space))->assertForbidden();
        $this->actingAs($user)->put(route('manager.spaces.update', $this->space), spaceData())->assertForbidden();

        expect($this->space->fresh()->name)->toBe('Espacio Propio');
    })->with([
        'usuario' => RoleName::Member,
        'recepción' => RoleName::Reception,
        'contable' => RoleName::Accountant,
    ]);

    test('a guest goes to the login', function () {
        $this->get(route('manager.spaces.edit', $this->space))->assertRedirect(route('login'));
        $this->put(route('manager.spaces.update', $this->space), spaceData())->assertRedirect(route('login'));
    });
});

describe('validation', function () {
    test('rejects invalid data with messages in Spanish', function (array $data, string $field, string $message) {
        $this->actingAs($this->manager)
            ->from(route('manager.spaces.edit', $this->space))
            ->put(route('manager.spaces.update', $this->space), spaceData($data))
            ->assertRedirect(route('manager.spaces.edit', $this->space))
            ->assertSessionHasErrors([$field => $message]);

        expect($this->space->fresh()->name)->toBe('Espacio Propio');
    })->with([
        'sin nombre' => [['name' => ''], 'name', 'El nombre es obligatorio.'],
        'nombre repetido' => [['name' => 'Espacio Ajeno'], 'name', 'Ya existe un espacio con ese nombre.'],
        'sin ubicación' => [['location' => ''], 'location', 'La ubicación es obligatoria.'],
        'antelación negativa' => [['cancellation_notice_hours' => -1], 'cancellation_notice_hours', 'La antelación de cancelación no puede ser negativa.'],
        'antelación no entera' => [['cancellation_notice_hours' => '2.5'], 'cancellation_notice_hours', 'La antelación de cancelación debe ser un número entero de horas.'],
    ]);

    test('keeps its own name and allows an empty description', function () {
        $this->actingAs($this->manager)
            ->put(route('manager.spaces.update', $this->space), spaceData(['name' => 'Espacio Propio', 'description' => '']))
            ->assertSessionHasNoErrors();

        expect($this->space->fresh()->description)->toBeNull();
    });
});
