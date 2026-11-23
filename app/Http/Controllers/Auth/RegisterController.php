<?php

namespace App\Http\Controllers\Auth;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        // Usuario y rol juntos o nada: si falla la asignación del rol, no queda un usuario sin rol.
        $user = DB::transaction(function () use ($request): User {
            $user = User::create($request->validated());

            $user->assignRole(RoleName::Member);

            return $user;
        });

        Auth::login($user);

        // Igual que en el login: nuevo identificador de sesión contra la fijación de sesión.
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
