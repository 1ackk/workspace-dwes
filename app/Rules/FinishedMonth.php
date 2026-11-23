<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * Un mes AAAA-MM ya terminado: su último día es anterior a hoy, en la zona horaria de la
 * aplicación. Solo se puede facturar un mes cerrado. La usan invoices:generate y el formulario del
 * panel de contabilidad, así que la regla está en un solo sitio.
 */
class FinishedMonth implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $month = is_string($value) ? self::parse($value) : null;

        if ($month === null) {
            $fail(__('El mes debe tener el formato AAAA-MM, por ejemplo 2026-10.'));

            return;
        }

        if (! $month->copy()->endOfMonth()->startOfDay()->lessThan(today())) {
            $fail(__('Solo se puede facturar un mes ya terminado.'));
        }
    }

    /**
     * El primer día del mes, o null si el texto no es exactamente AAAA-MM con un mes real.
     */
    public static function parse(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return null;
        }

        $month = Carbon::createFromFormat('!Y-m', $value, config('app.timezone'));

        return $month instanceof Carbon ? $month : null;
    }
}
