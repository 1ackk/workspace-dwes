<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MyBookingController extends Controller
{
    public function index(Request $request): View
    {
        $bookings = $request->user()->bookings()
            ->with('resources')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(10);

        return view('my-bookings.index', ['bookings' => $bookings]);
    }

    public function show(Booking $booking): View
    {
        Gate::authorize('view', $booking);

        $booking->load('resources.space');

        return view('my-bookings.show', ['booking' => $booking]);
    }
}
