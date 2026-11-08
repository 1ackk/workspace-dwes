<?php

use App\Models\Booking;
use App\Models\Incident;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Resource;
use App\Models\Space;
use App\Models\Tariff;
use App\Models\User;

it('relates a user with the spaces they manage, their bookings, invoices and incidents', function () {
    $user = User::factory()->create();
    $space = Space::factory()->for($user, 'manager')->create();
    $booking = Booking::factory()->for($user)->create();
    $invoice = Invoice::factory()->for($user)->create();
    $incident = Incident::factory()->for($user)->create();

    expect($user->managedSpaces->pluck('id')->all())->toEqual([$space->id])
        ->and($user->bookings->pluck('id')->all())->toEqual([$booking->id])
        ->and($user->invoices->pluck('id')->all())->toEqual([$invoice->id])
        ->and($user->incidents->pluck('id')->all())->toEqual([$incident->id])
        ->and($space->manager->is($user))->toBeTrue()
        ->and($booking->user->is($user))->toBeTrue()
        ->and($invoice->user->is($user))->toBeTrue()
        ->and($incident->user->is($user))->toBeTrue();
});

it('relates a space with its resources in both directions', function () {
    $space = Space::factory()->create();
    $resources = Resource::factory()->count(2)->for($space)->create();

    expect($space->resources->pluck('id')->sort()->values()->all())->toEqual($resources->pluck('id')->sort()->values()->all())
        ->and($resources->first()->space->is($space))->toBeTrue();
});

it('relates a resource with its tariffs, incidents, bookings and slots', function () {
    $resource = Resource::factory()->create();
    $tariff = Tariff::factory()->for($resource)->create();
    $incident = Incident::factory()->for($resource)->create();
    $booking = Booking::factory()
        ->withSlot($resource, now()->addDay()->setTime(10, 0), now()->addDay()->setTime(11, 0))
        ->create();

    expect($resource->tariffs->pluck('id')->all())->toEqual([$tariff->id])
        ->and($resource->incidents->pluck('id')->all())->toEqual([$incident->id])
        ->and($resource->bookings->pluck('id')->all())->toEqual([$booking->id])
        ->and($resource->slots->pluck('booking_id')->all())->toEqual([$booking->id])
        ->and($tariff->resource->is($resource))->toBeTrue()
        ->and($incident->resource->is($resource))->toBeTrue();
});

it('allows a general tariff that belongs to no resource', function () {
    $tariff = Tariff::factory()->afternoon()->create();

    expect($tariff->resource_id)->toBeNull()
        ->and($tariff->resource)->toBeNull();
});

it('relates a booking with its slots, invoice, payments and incidents', function () {
    $resource = Resource::factory()->create();
    $invoice = Invoice::factory()->create();
    $booking = Booking::factory()
        ->withSlot($resource, now()->addDay()->setTime(10, 0), now()->addDay()->setTime(12, 0))
        ->create(['invoice_id' => $invoice->id]);
    $payment = Payment::factory()->for($invoice)->for($booking)->create();
    $incident = Incident::factory()->for($booking)->create();
    $slot = $booking->slots->first();

    expect($booking->slots)->toHaveCount(1)
        ->and($slot->booking->is($booking))->toBeTrue()
        ->and($slot->resource->is($resource))->toBeTrue()
        ->and($booking->invoice->is($invoice))->toBeTrue()
        ->and($booking->payments->pluck('id')->all())->toEqual([$payment->id])
        ->and($booking->incidents->pluck('id')->all())->toEqual([$incident->id])
        ->and($incident->booking->is($booking))->toBeTrue();
});

it('relates an invoice with its bookings and payments', function () {
    $invoice = Invoice::factory()->create();
    $booking = Booking::factory()->create(['invoice_id' => $invoice->id]);
    $payment = Payment::factory()->for($invoice)->create();

    expect($invoice->bookings->pluck('id')->all())->toEqual([$booking->id])
        ->and($invoice->payments->pluck('id')->all())->toEqual([$payment->id])
        ->and($payment->invoice->is($invoice))->toBeTrue()
        ->and($payment->booking)->toBeNull();
});
