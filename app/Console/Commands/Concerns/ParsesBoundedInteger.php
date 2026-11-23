<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Validator;

trait ParsesBoundedInteger
{
    /**
     * Convierte el texto de una opción en un entero entre $min y $max, o null si no lo es.
     * "abc", "2.5", "" o un número fuera del rango dan null.
     */
    private function parseBoundedInteger(mixed $value, int $min, int $max): ?int
    {
        $isValid = is_string($value)
            && Validator::make(['value' => $value], ['value' => "required|integer|between:{$min},{$max}"])->passes();

        return $isValid ? (int) $value : null;
    }

    private function invalidInteger(string $option, mixed $value, int $min, int $max): int
    {
        $this->error(__('Valor no válido para --:option: ":value". Debe ser un número entero entre :min y :max.', [
            'option' => $option,
            'value' => is_string($value) ? $value : '',
            'min' => $min,
            'max' => $max,
        ]));

        return self::FAILURE;
    }
}
