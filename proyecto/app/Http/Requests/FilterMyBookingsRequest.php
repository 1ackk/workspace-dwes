<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterMyBookingsRequest extends FormRequest
{
    /**
     * Con un filtro inválido vuelve siempre al listado limpio.
     *
     * @var string
     */
    protected $redirectRoute = 'my-bookings.index';

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
            'from' => ['nullable', 'date'],
            // Sin "desde" no hay con qué comparar y after_or_equal fallaría.
            'to' => ['nullable', 'date', Rule::when($this->filled('from'), 'after_or_equal:from')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.date' => __('La fecha inicial no es válida.'),
            'to.date' => __('La fecha final no es válida.'),
            'to.after_or_equal' => __('La fecha final no puede ser anterior a la inicial.'),
        ];
    }
}
