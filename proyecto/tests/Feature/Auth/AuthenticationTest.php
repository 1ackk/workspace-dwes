<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

function loginWith(string $email, string $password = 'password'): array
{
    return ['email' => $email, 'password' => $password];
}

describe('login', function () {
    test('shows the login form', function () {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('name="remember"', false);
    });

    test('logs in with valid credentials', function () {
        $user = User::factory()->create();

        $this->post(route('login'), loginWith($user->email))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    });

    test('regenerates the session when logging in', function () {
        $user = User::factory()->create();
        $sessionId = session()->getId();

        $this->post(route('login'), loginWith($user->email));

        expect(session()->getId())->not->toBe($sessionId);
    });

    test('sends the user back to the page they wanted', function () {
        $user = User::factory()->create();

        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->post(route('login'), loginWith($user->email))
            ->assertRedirect(route('dashboard'));
    });

    test('sets the remember cookie only when asked', function () {
        $user = User::factory()->create();
        $cookie = Auth::guard()->getRecallerName();

        $this->post(route('login'), loginWith($user->email))
            ->assertCookieMissing($cookie);

        Auth::logout();

        $this->post(route('login'), [...loginWith($user->email), 'remember' => '1'])
            ->assertCookie($cookie);
    });

    test('rejects a wrong password and shows the error', function () {
        $user = User::factory()->create();

        $this->from(route('login'))
            ->followingRedirects()
            ->post(route('login'), loginWith($user->email, 'incorrecta'))
            ->assertOk()
            ->assertSee(__('Las credenciales no son correctas.'))
            ->assertSee('value="'.$user->email.'"', false);

        $this->assertGuest();
    });

    test('rejects an unknown email', function () {
        $this->post(route('login'), loginWith('nadie@workspace.test'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    });

    test('rejects a soft deleted user', function () {
        $user = User::factory()->create();
        $user->delete();

        $this->post(route('login'), loginWith($user->email))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    });

    test('requires email and password', function () {
        $this->post(route('login'), [])
            ->assertSessionHasErrors(['email', 'password']);
    });
});

describe('rate limiting', function () {
    test('blocks the login after too many failed attempts', function () {
        $this->freezeTime();
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login'), loginWith($user->email, 'incorrecta'))
                ->assertSessionHasErrors([
                    'email' => __('Las credenciales no son correctas.'),
                ]);
        }

        $this->post(route('login'), loginWith($user->email))
            ->assertSessionHasErrors([
                'email' => __('Demasiados intentos. Inténtalo de nuevo en :seconds segundos.', ['seconds' => 60]),
            ]);

        $this->assertGuest();
    });

    test('does not block a different email', function () {
        $blocked = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, 6) as $attempt) {
            $this->post(route('login'), loginWith($blocked->email, 'incorrecta'));
        }

        $this->post(route('login'), loginWith($other->email));

        $this->assertAuthenticatedAs($other);
    });

    test('clears the failed attempts after a successful login', function () {
        $user = User::factory()->create();

        foreach (range(1, 2) as $round) {
            foreach (range(1, 4) as $attempt) {
                $this->post(route('login'), loginWith($user->email, 'incorrecta'));
            }

            $this->post(route('login'), loginWith($user->email));
            $this->assertAuthenticatedAs($user);

            Auth::logout();
        }
    });
});

describe('logout', function () {
    test('logs out, invalidates the session and regenerates the token', function () {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['marker' => 'value']);
        $token = session()->token();

        $this->post(route('logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
        expect(session()->has('marker'))->toBeFalse()
            ->and(session()->token())->not->toBe($token);
    });

    test('is not available by GET', function () {
        $this->actingAs(User::factory()->create())
            ->get('/logout')
            ->assertMethodNotAllowed();

        $this->assertAuthenticated();
    });

    test('redirects a guest to the login', function () {
        $this->post(route('logout'))
            ->assertRedirect(route('login'));
    });
});

describe('zones', function () {
    test('the home page is public', function () {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(__('Iniciar sesión'))
            ->assertSee(__('Crear cuenta'))
            ->assertDontSee(__('Cerrar sesión'));
    });

    test('a guest is redirected from the dashboard to the login', function () {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    });

    test('the dashboard says who has logged in', function () {
        $user = User::factory()->create(['name' => 'Ana López']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ana López')
            ->assertSee($user->email);
    });

    test('the navigation changes with a session', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSee(__('Cerrar sesión'))
            ->assertDontSee(__('Crear cuenta'));
    });

    test('an authenticated user is redirected from the guest pages', function (string $routeName) {
        $this->actingAs(User::factory()->create())
            ->get(route($routeName))
            ->assertRedirect(route('dashboard'));
    })->with(['login', 'register']);
});
