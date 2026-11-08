<?php

namespace App\Http\Requests\Manager;

use Illuminate\Validation\Rules\Unique;

class UpdateResourceRequest extends StoreResourceRequest
{
    /**
     * Exige poder editar el recurso y poder crear en el espacio de destino.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('resource')) && parent::authorize();
    }

    protected function uniqueNameRule(): Unique
    {
        return parent::uniqueNameRule()->ignore($this->route('resource'));
    }
}
