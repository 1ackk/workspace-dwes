<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('incidents.view-any');
    }

    public function view(User $user, Incident $incident): bool
    {
        if (! $user->can('incidents.view')) {
            return false;
        }

        return $user->hasAnyRole([RoleName::Admin, RoleName::Reception])
            || $this->isAuthor($user, $incident)
            || $this->managesResource($user, $incident);
    }

    public function create(User $user): bool
    {
        return $user->can('incidents.create');
    }

    /**
     * Comentar: el autor (también recepción, solo en las suyas), el responsable y el administrador.
     */
    public function respond(User $user, Incident $incident): bool
    {
        if (! $user->can('incidents.respond')) {
            return false;
        }

        return $user->hasRole(RoleName::Admin)
            || $this->isAuthor($user, $incident)
            || $this->managesResource($user, $incident);
    }

    /**
     * Quién puede cambiar el estado o cerrarla. Qué cambios son válidos no se decide aquí.
     */
    public function update(User $user, Incident $incident): bool
    {
        if (! $user->can('incidents.update')) {
            return false;
        }

        return $user->hasRole(RoleName::Admin) || $this->managesResource($user, $incident);
    }

    /**
     * Las incidencias no se borran: la matriz no da ese permiso a ningún rol.
     */
    public function delete(User $user, Incident $incident): bool
    {
        return false;
    }

    private function isAuthor(User $user, Incident $incident): bool
    {
        return $incident->user_id === $user->id;
    }

    /**
     * El responsable se deriva del espacio del recurso, igual que en ResourcePolicy.
     */
    private function managesResource(User $user, Incident $incident): bool
    {
        return $user->hasRole(RoleName::SpaceManager)
            && $incident->resource->space->manager_id === $user->id;
    }
}
