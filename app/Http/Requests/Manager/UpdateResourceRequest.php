<?php

namespace App\Http\Requests\Manager;

use App\Models\Resource;

class UpdateResourceRequest extends StoreResourceRequest
{
    /**
     * Exige poder editar el recurso y poder crear en el espacio de destino.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('resource')) && parent::authorize();
    }

    protected function resourceBeingEdited(): ?Resource
    {
        $resource = $this->route('resource');

        return $resource instanceof Resource ? $resource : null;
    }
}
