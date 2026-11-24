<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);

    $this->user = userWithRole(RoleName::Member);
});

test('a guest goes to the login', function () {
    $this->get(route('account.notifications.edit'))->assertRedirect(route('login'));
    $this->put(route('account.notifications.update'), ['telegram_chat_id' => '123456789'])->assertRedirect(route('login'));
});

test('the screen shows the form, the current value and how to get the id, linked from the menu', function () {
    $this->user->forceFill(['telegram_chat_id' => '100000001'])->save();

    $this->actingAs($this->user)
        ->get(route('account.notifications.edit'))
        ->assertOk()
        ->assertSee('value="100000001"', false)
        ->assertSee(route('account.notifications.update'))
        ->assertSee('name="_token"', false)
        ->assertSee('Cómo obtener tu identificador')
        ->assertSee('El enlace no es automático');

    $this->actingAs($this->user)->get(route('dashboard'))->assertSee(route('account.notifications.edit'));
});

test('saves the chat id of the authenticated user', function () {
    $this->actingAs($this->user)
        ->put(route('account.notifications.update'), ['telegram_chat_id' => ' 123456789 '])
        ->assertRedirect(route('account.notifications.edit'))
        ->assertSessionHas('status', 'Avisos por Telegram activados.');

    expect($this->user->fresh()->telegram_chat_id)->toBe('123456789');
});

test('accepts a negative group id', function () {
    $this->actingAs($this->user)
        ->put(route('account.notifications.update'), ['telegram_chat_id' => '-1001234567890'])
        ->assertSessionHasNoErrors();

    expect($this->user->fresh()->telegram_chat_id)->toBe('-1001234567890');
});

test('an empty field turns Telegram off', function () {
    $this->user->forceFill(['telegram_chat_id' => '100000001'])->save();

    $this->actingAs($this->user)
        ->put(route('account.notifications.update'), ['telegram_chat_id' => ''])
        ->assertSessionHas('status', 'Avisos por Telegram desactivados.');

    expect($this->user->fresh()->telegram_chat_id)->toBeNull();
});

test('rejects an id that is not a number of 5 to 15 digits', function (mixed $value) {
    $this->actingAs($this->user)
        ->from(route('account.notifications.edit'))
        ->put(route('account.notifications.update'), ['telegram_chat_id' => $value])
        ->assertRedirect(route('account.notifications.edit'))
        ->assertSessionHasErrors('telegram_chat_id');

    expect($this->user->fresh()->telegram_chat_id)->toBeNull();
})->with(['@usuario', '12a456789', '1234', '1234567890123456', '12 345 678', '--12345', '+12345678']);

test('the format error is in Spanish', function () {
    $this->actingAs($this->user)
        ->put(route('account.notifications.update'), ['telegram_chat_id' => 'abc'])
        ->assertSessionHasErrors(['telegram_chat_id' => 'El identificador de chat debe ser un número de 5 a 15 cifras (puede empezar por un signo menos).']);
});

test('an id already linked to another account is rejected; keeping your own is fine', function () {
    userWithRole(RoleName::Member)->forceFill(['telegram_chat_id' => '555555555'])->save();
    $this->user->forceFill(['telegram_chat_id' => '100000001'])->save();

    $this->actingAs($this->user)
        ->put(route('account.notifications.update'), ['telegram_chat_id' => '555555555'])
        ->assertSessionHasErrors(['telegram_chat_id' => 'Ese identificador de chat ya está enlazado a otra cuenta.']);

    $this->actingAs($this->user)
        ->put(route('account.notifications.update'), ['telegram_chat_id' => '100000001'])
        ->assertSessionHasNoErrors();
});

test('it always acts on the authenticated user, whatever ids the request carries', function () {
    $other = userWithRole(RoleName::Member);

    $this->actingAs($this->user)
        ->put(route('account.notifications.update'), [
            'telegram_chat_id' => '123456789',
            'id' => $other->id,
            'user_id' => $other->id,
            'email' => 'robado@workspace.test',
        ])
        ->assertSessionHasNoErrors();

    expect($this->user->fresh()->telegram_chat_id)->toBe('123456789')
        ->and($this->user->fresh()->email)->not->toBe('robado@workspace.test')
        ->and($other->fresh()->telegram_chat_id)->toBeNull();
});

test('the chat id is not mass assignable and the registration cannot fill it', function () {
    expect((new User)->isFillable('telegram_chat_id'))->toBeFalse();

    $this->post(route('register.store'), [
        'name' => 'Nueva',
        'email' => 'nueva@workspace.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'telegram_chat_id' => '777777777',
    ])->assertRedirect(route('dashboard'));

    expect(User::query()->where('email', 'nueva@workspace.test')->sole()->telegram_chat_id)->toBeNull();
});
