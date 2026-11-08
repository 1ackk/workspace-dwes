<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

function validRegistration(array $overrides = []): array
{
    return [
        'name' => 'Ana López',
        'email' => 'ana@workspace.test',
        'password' => 'secreto-de-prueba',
        'password_confirmation' => 'secreto-de-prueba',
        ...$overrides,
    ];
}

test('shows the registration form', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('name="password_confirmation"', false);
});

test('registers a user and logs them in', function () {
    $this->post(route('register'), validRegistration())
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'ana@workspace.test')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Ana López')
        ->and(Hash::check('secreto-de-prueba', $user->password))->toBeTrue();
});

test('regenerates the session when registering', function () {
    $sessionId = session()->getId();

    $this->post(route('register'), validRegistration());

    expect(session()->getId())->not->toBe($sessionId);
});

test('stores the email in lowercase', function () {
    $this->post(route('register'), validRegistration(['email' => '  ANA@workspace.test ']));

    expect(User::where('email', 'ana@workspace.test')->exists())->toBeTrue();
});

test('rejects an email that is already registered and shows the error', function () {
    User::factory()->create(['email' => 'ana@workspace.test']);

    $this->from(route('register'))
        ->followingRedirects()
        ->post(route('register'), validRegistration())
        ->assertOk()
        ->assertSee(__('Ya existe una cuenta con este correo electrónico.'))
        ->assertSee('value="Ana López"', false);

    $this->assertGuest();
    expect(User::count())->toBe(1);
});

test('rejects a registered email written in another case', function () {
    User::factory()->create(['email' => 'ana@workspace.test']);

    $this->post(route('register'), validRegistration(['email' => 'ANA@workspace.test']))
        ->assertSessionHasErrors('email');

    expect(User::count())->toBe(1);
});

test('rejects the email of a soft deleted user with a validation error', function () {
    User::factory()->create(['email' => 'ana@workspace.test'])->delete();

    $this->post(route('register'), validRegistration())
        ->assertRedirect()
        ->assertSessionHasErrors([
            'email' => __('Ya existe una cuenta con este correo electrónico.'),
        ]);

    $this->assertGuest();
    expect(User::withTrashed()->where('email', 'ana@workspace.test')->count())->toBe(1);
});

test('rejects a password without confirmation and shows the error', function () {
    $this->from(route('register'))
        ->followingRedirects()
        ->post(route('register'), validRegistration(['password_confirmation' => 'otra-distinta']))
        ->assertOk()
        ->assertSee(__('La confirmación de la contraseña no coincide.'));

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('rejects a short password', function () {
    $this->post(route('register'), validRegistration(['password' => 'corta', 'password_confirmation' => 'corta']))
        ->assertSessionHasErrors([
            'password' => __('La contraseña debe tener al menos :min caracteres.', ['min' => 8]),
        ]);
});

test('rejects missing data', function () {
    $this->post(route('register'), [])
        ->assertSessionHasErrors(['name', 'email', 'password']);

    expect(User::count())->toBe(0);
});

test('rejects an email sent as an array', function () {
    $this->post(route('register'), validRegistration(['email' => ['ana@workspace.test']]))
        ->assertSessionHasErrors('email');
});
