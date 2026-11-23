<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingNotConfirmableException;
use App\Http\Requests\FilterMyBookingsRequest;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MyBookingController extends Controller
{
    public function index(FilterMyBookingsRequest $request): View
    {
        $bookings = $request->user()->bookings()
            ->with('resources')
            ->when($request->validated('from'), fn ($query, $from) => $query->whereDate('date', '>=', $from))
            ->when($request->validated('to'), fn ($query, $to) => $query->whereDate('date', '<=', $to))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return view('my-bookings.index', ['bookings' => $bookings]);
    }

    public function show(Booking $booking): View
    {
        Gate::authorize('view', $booking);

        $booking->load(['resources.space', 'invoice']);

        return view('my-bookings.show', ['booking' => $booking]);
    }

    /**
     * Pago simulado: confirma la reserva. Quién puede lo decide la ruta (can:confirm,booking);
     * si todavía se puede, BookingService::confirm.
     */
    public function confirm(Booking $booking, BookingService $bookings): RedirectResponse
    {
        try {
            $bookings->confirm($booking);
        } catch (BookingNotConfirmableException $exception) {
            return to_route('my-bookings.show', $booking)->with('error', $exception->getMessage());
        }

        return to_route('my-bookings.show', $booking)
            ->with('status', __('Pago recibido: tu reserva está confirmada. Te hemos enviado un correo con el resumen.'));
    }
}
