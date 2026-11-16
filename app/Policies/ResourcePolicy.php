<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Resource;
use App\Models\Space;
use App\Models\User;

class ResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('resources.view-any');
    }

    public function view(User $user, Resource $resource): bool
    {
        if (! $user->can('resources.view-any')) {
            return false;
        }

        return $user->hasRole(RoleName::Reception) || $this->canManage($user, $resource->space);
    }

    /**
     * Sin espacio responde si puede abrir el formulario; con espacio, si puede crear en él.
     */
    public function create(User $user, ?Space $space = null): bool
    {
        return $user->can('resources.create')
            && ($space === null || $this->canManage($user, $space));
    }

    public function update(User $user, Resource $resource): bool
    {
        return $user->can('resources.update') && $this->canManage($user, $resource->space);
    }

    public function delete(User $user, Resource $resource): bool
    {
        return $user->can('resources.delete');
    }

    /**
     * El administrador gestiona cualquier espacio; el responsable, solo los suyos.
     */
    private function canManage(User $user, Space $space): bool
    {
        return $user->hasRole(RoleName::Admin)
            || ($user->hasRole(RoleName::SpaceManager) && $space->manager_id === $user->id);
    }
}
