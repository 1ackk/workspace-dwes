<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class IncidentController extends Controller
{
    /**
     * Detalle de una incidencia. IncidentPolicy::view deja verla al autor, al responsable del
     * espacio de su recurso, a recepción y al administrador; el responsable de otro espacio
     * recibe un 403.
     */
    public function show(Incident $incident): View
    {
        // Se carga antes de autorizar: la policy necesita el espacio del recurso y lo reutiliza.
        $incident->load(['resource.space', 'user']);

        Gate::authorize('view', $incident);

        return view('incidents.show', ['incident' => $incident]);
    }
}
