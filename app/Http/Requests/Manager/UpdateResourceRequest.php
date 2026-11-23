<?php

namespace App\Http\Requests\Manager;

use App\Models\Resource;

class UpdateResourceRequest extends StoreResourceRequest
{
    /**
     * Exige poder editar el recurso y poder crear en el espacio de destino. La segunda
     * condición (parent::authorize) es la que impide mover un recurso a un espacio ajeno.
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
