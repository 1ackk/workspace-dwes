<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Nuevo identificador de sesión al entrar: evita la fijación de sesión, en la que un
        // atacante reutiliza un identificador que conocía de antes del login.
        $request->session()->regenerate();

        // Vuelve a la página privada que pidió antes de que le mandaran al login, o al panel.

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        // Borra los datos de la sesión y cambia el token CSRF: los formularios abiertos con la
        // sesión anterior ya no sirven.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
