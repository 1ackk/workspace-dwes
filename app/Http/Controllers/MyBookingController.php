<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterMyBookingsRequest;
use App\Models\Booking;
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

        $booking->load('resources.space');

        return view('my-bookings.show', ['booking' => $booking]);
    }
}
