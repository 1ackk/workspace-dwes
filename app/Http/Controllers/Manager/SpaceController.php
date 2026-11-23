<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\UpdateSpaceRequest;
use App\Models\Space;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Gestión de espacios. La autorización está en las rutas (middleware can), que llaman a
 * SpacePolicy: viewManagement para el listado y update para editar y guardar.
 */
class SpaceController extends Controller
{
    /**
     * El administrador ve todos los espacios; el responsable, solo los suyos.
     */
    public function index(Request $request): View
    {
        $spaces = $request->user()->manageableSpaces()
            ->withCount('resources')
            ->orderBy('name')
            ->get();

        return view('manager.spaces.index', ['spaces' => $spaces]);
    }

    public function edit(Space $space): View
    {
        return view('manager.spaces.edit', ['space' => $space]);
    }

    public function update(UpdateSpaceRequest $request, Space $space): RedirectResponse
    {
        $space->update($request->validated());

        return redirect()->route('manager.spaces.index')
            ->with('status', __('Espacio actualizado.'));
    }
}
