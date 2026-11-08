<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // El índice único de users.email también cuenta a los usuarios borrados.
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('El nombre es obligatorio.'),
            'name.max' => __('El nombre no puede superar los :max caracteres.'),
            'email.required' => __('El correo electrónico es obligatorio.'),
            'email.email' => __('El correo electrónico no es válido.'),
            'email.max' => __('El correo electrónico no puede superar los :max caracteres.'),
            'email.unique' => __('Ya existe una cuenta con este correo electrónico.'),
            'password.required' => __('La contraseña es obligatoria.'),
            'password.confirmed' => __('La confirmación de la contraseña no coincide.'),
            'password.min' => __('La contraseña debe tener al menos :min caracteres.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
