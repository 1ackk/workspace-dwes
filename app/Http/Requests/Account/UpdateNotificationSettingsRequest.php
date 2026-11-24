<?php

namespace App\Http\Requests\Account;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Preferencias de aviso del usuario autenticado. Solo hay un campo: el id de chat de Telegram
 * (vacío = desactivado). Un id de chat es un número entero; los de grupo son negativos.
 */
class UpdateNotificationSettingsRequest extends FormRequest
{
    /**
     * La ruta solo exige sesión: siempre se actúa sobre el propio usuario, nunca sobre un id recibido.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $chatId = $this->input('telegram_chat_id');

        if (is_string($chatId)) {
            $this->merge(['telegram_chat_id' => trim($chatId)]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'telegram_chat_id' => [
                'nullable',
                'string',
                'regex:/^-?\d{5,15}$/',
                Rule::unique('users', 'telegram_chat_id')->ignore($this->user()->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'telegram_chat_id.string' => __('El identificador de chat no es válido.'),
            'telegram_chat_id.regex' => __('El identificador de chat debe ser un número de 5 a 15 cifras (puede empezar por un signo menos).'),
            'telegram_chat_id.unique' => __('Ese identificador de chat ya está enlazado a otra cuenta.'),
        ];
    }
}
