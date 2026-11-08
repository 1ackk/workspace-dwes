<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view-any');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if (! $user->can('invoices.view')) {
            return false;
        }

        return $user->hasAnyRole([RoleName::Admin, RoleName::Accountant])
            || $invoice->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.create');
    }

    /**
     * Quién puede anular. Si la factura se puede anular según su estado no se decide aquí.
     */
    public function void(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.void');
    }

    /**
     * Las facturas no se borran, ni siquiera el administrador: se anulan y se rectifican.
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }
}
