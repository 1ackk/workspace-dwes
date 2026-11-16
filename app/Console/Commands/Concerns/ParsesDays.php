<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

trait ParsesDays
{
    /**
     * Convierte un día AAAA-MM-DD en las 00:00 de ese día, o null si no es una fecha real.
     */
    private function parseDay(string $value): ?Carbon
    {
        $isValid = Validator::make(['day' => $value], ['day' => 'required|date_format:Y-m-d'])->passes();

        return $isValid ? Carbon::parse($value) : null;
    }

    /**
     * Symfony tipa los argumentos y las opciones como mixed; estos comandos solo reciben texto.
     */
    private function asText(mixed $input): string
    {
        return is_string($input) ? $input : '';
    }

    private function invalidDay(string $value): int
    {
        $this->error(__('Fecha no válida: ":value". Usa el formato AAAA-MM-DD.', ['value' => $value]));

        return self::FAILURE;
    }
}
