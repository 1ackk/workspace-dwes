<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Space;
use App\Models\User;

class SpacePolicy
{
    /**
     * El catálogo es público: $user es nulo para un visitante.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Space $space): bool
    {
        return true;
    }

    /**
     * Abrir la pantalla de gestión de espacios, que no es el catálogo público.
     */
    public function viewManagement(User $user): bool
    {
        return $user->can('spaces.view-any');
    }

    public function create(User $user): bool
    {
        return $user->can('spaces.create');
    }

    /**
     * El administrador gestiona cualquier espacio; el responsable, solo los suyos.
     */
    public function update(User $user, Space $space): bool
    {
        if (! $user->can('spaces.update')) {
            return false;
        }

        return $user->hasRole(RoleName::Admin)
            || ($user->hasRole(RoleName::SpaceManager) && $space->manager_id === $user->id);
    }

    public function delete(User $user, Space $space): bool
    {
        return $user->can('spaces.delete');
    }
}
