<?php

namespace App\Http\Requests\Manager;

use App\Enums\ResourceType;
use App\Models\Resource;
use App\Models\Space;
use App\Rules\UniqueResourceName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResourceRequest extends FormRequest
{
    /**
     * Un espacio ajeno da 403 aquí, antes de validar el resto de campos.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', [Resource::class, $this->targetSpace()]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'space_id' => ['required', 'integer', Rule::exists('spaces', 'id')],
            'name' => [
                'required',
                'string',
                'max:255',
                new UniqueResourceName($this->integer('space_id'), $this->resourceBeingEdited()),
            ],
            'type' => ['required', Rule::enum(ResourceType::class)],
            'capacity' => ['required', 'integer', 'min:0', 'max:65535'],
            'hourly_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'space_id.required' => __('El espacio es obligatorio.'),
            'space_id.exists' => __('El espacio no existe.'),
            'name.required' => __('El nombre es obligatorio.'),
            'name.max' => __('El nombre no puede superar los :max caracteres.'),
            'type.required' => __('El tipo es obligatorio.'),
            'type.enum' => __('El tipo no es válido.'),
            'capacity.required' => __('La capacidad es obligatoria.'),
            'capacity.integer' => __('La capacidad debe ser un número entero.'),
            'capacity.min' => __('La capacidad no puede ser negativa.'),
            'capacity.max' => __('La capacidad no puede superar :max.'),
            'hourly_rate.required' => __('La tarifa por hora es obligatoria.'),
            'hourly_rate.numeric' => __('La tarifa por hora debe ser un número.'),
            'hourly_rate.decimal' => __('La tarifa por hora admite como máximo dos decimales.'),
            'hourly_rate.min' => __('La tarifa por hora no puede ser negativa.'),
            'hourly_rate.max' => __('La tarifa por hora no puede superar :max.'),
        ];
    }

    protected function targetSpace(): ?Space
    {
        return Space::find($this->integer('space_id'));
    }

    protected function resourceBeingEdited(): ?Resource
    {
        return null;
    }
}
