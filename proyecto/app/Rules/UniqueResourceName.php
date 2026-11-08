<?php

namespace App\Rules;

use App\Models\Resource;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El nombre de un recurso no se repite dentro de su espacio, sin distinguir mayúsculas.
 * Al editar, se ignora el propio recurso.
 */
class UniqueResourceName implements ValidationRule
{
    public function __construct(
        private int $spaceId,
        private ?Resource $ignore = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $query = Resource::query()
            ->where('space_id', $this->spaceId)
            ->whereRaw('LOWER(name) = LOWER(?)', [$value]);

        if ($this->ignore !== null) {
            $query->whereKeyNot($this->ignore->id);
        }

        if ($query->exists()) {
            $fail(__('Ya existe un recurso con este nombre en el espacio.'));
        }
    }
}
