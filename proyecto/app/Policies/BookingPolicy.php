<?php

namespace App\Policies;

use App\Enums\BookingStatus;
use App\Enums\RoleName;
use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('bookings.view-any');
    }

    public function view(User $user, Booking $booking): bool
    {
        if (! $user->can('bookings.view')) {
            return false;
        }

        return $this->hasFullAccess($user)
            || $this->isOwner($user, $booking)
            || $this->managesBooking($user, $booking);
    }

    public function create(User $user): bool
    {
        return $user->can('bookings.create');
    }

    public function cancel(User $user, Booking $booking): bool
    {
        if (! $user->can('bookings.cancel') || ! $this->isCancellable($booking)) {
            return false;
        }

        return $this->hasFullAccess($user) || $this->isOwner($user, $booking);
    }

    /**
     * Los roles sin * en docs/permisos.md: no se limitan a lo suyo.
     */
    private function hasFullAccess(User $user): bool
    {
        return $user->hasAnyRole([RoleName::Admin, RoleName::Reception]);
    }

    private function isOwner(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id;
    }

    /**
     * La reserva incluye algún recurso de un espacio que este responsable gestiona.
     */
    private function managesBooking(User $user, Booking $booking): bool
    {
        return $user->hasRole(RoleName::SpaceManager)
            && $booking->resources()
                ->whereHas('space', fn ($query) => $query->where('manager_id', $user->id))
                ->exists();
    }

    private function isCancellable(Booking $booking): bool
    {
        if (in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::Expired], true)) {
            return false;
        }

        // Una pendiente vencida ya está caducada aunque el comando no haya cambiado su estado.
        return $booking->status !== BookingStatus::PendingPayment
            || $booking->expires_at === null
            || $booking->expires_at->isFuture();
    }
}
