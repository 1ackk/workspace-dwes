<?php

namespace App\Http\Requests\Manager;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos editables de un espacio. El horario (opens_at, closes_at) queda fuera a propósito:
 * cambiarlo podría dejar fuera de horario reservas que ya existen.
 */
class UpdateSpaceRequest extends FormRequest
{
    /**
     * La ruta ya ha pasado por el middleware can:update,space (SpacePolicy::update).
     */
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
            'name' => ['required', 'string', 'max:255', Rule::unique('spaces', 'name')->ignore($this->route('space'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['required', 'string', 'max:255'],
            'cancellation_notice_hours' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('El nombre es obligatorio.'),
            'name.string' => __('El nombre no es válido.'),
            'name.max' => __('El nombre no puede superar los :max caracteres.'),
            'name.unique' => __('Ya existe un espacio con ese nombre.'),
            'description.string' => __('La descripción no es válida.'),
            'description.max' => __('La descripción no puede superar los :max caracteres.'),
            'location.required' => __('La ubicación es obligatoria.'),
            'location.string' => __('La ubicación no es válida.'),
            'location.max' => __('La ubicación no puede superar los :max caracteres.'),
            'cancellation_notice_hours.required' => __('La antelación de cancelación es obligatoria.'),
            'cancellation_notice_hours.integer' => __('La antelación de cancelación debe ser un número entero de horas.'),
            'cancellation_notice_hours.min' => __('La antelación de cancelación no puede ser negativa.'),
            'cancellation_notice_hours.max' => __('La antelación de cancelación no puede superar :max horas.'),
        ];
    }
}
