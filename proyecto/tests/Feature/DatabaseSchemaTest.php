<?php

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\Incident;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Resource;
use App\Models\Space;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('creates every domain table', function () {
    foreach (['users', 'spaces', 'resources', 'tariffs', 'invoices', 'bookings', 'booking_resource', 'payments', 'incidents'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }

    expect(Schema::hasColumns('booking_resource', ['booking_id', 'resource_id', 'start_at', 'end_at', 'precio_final']))->toBeTrue();
});

it('stores slot and final price in the booking-resource pivot', function () {
    $room = Resource::factory()->meetingRoom()->create();
    $projector = Resource::factory()->projector()->for($room->space)->create();
    $start = now()->addDay()->setTime(10, 0);

    $booking = Booking::factory()->confirmed()
        ->withSlot($room, $start->copy(), $start->copy()->addHours(2))
        ->withSlot($projector, $start->copy(), $start->copy()->addHours(2))
        ->create();

    expect($booking->resources)->toHaveCount(2);

    $pivot = $booking->resources->firstWhere('id', $room->id)->pivot;

    expect($pivot)->toBeInstanceOf(BookingResource::class)
        ->and($pivot->precio_final)->toBe('50.00')
        ->and($pivot->start_at->equalTo($start))->toBeTrue()
        ->and($booking->refresh()->total)->toBe('60.00');
});

it('groups several bookings of the same user in one invoice', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->for($user)->create();

    Booking::factory()->count(2)->for($user)->create(['invoice_id' => $invoice->id]);

    expect($invoice->bookings)->toHaveCount(2)
        ->and($invoice->bookings->first()->invoice->is($invoice))->toBeTrue();
});

it('derives the resource manager from its space', function () {
    $manager = User::factory()->create();
    $space = Space::factory()->for($manager, 'manager')->create();
    $resource = Resource::factory()->for($space)->create();

    expect($resource->manager->is($manager))->toBeTrue()
        ->and($manager->managedSpaces)->toHaveCount(1);
});

it('soft deletes users, bookings and invoices to keep the history', function () {
    $user = User::factory()->create();
    $booking = Booking::factory()->create();
    $invoice = Invoice::factory()->create();

    $user->delete();
    $booking->delete();
    $invoice->delete();

    $this->assertSoftDeleted($user);
    $this->assertSoftDeleted($booking);
    $this->assertSoftDeleted($invoice);
});

it('rectifies a void invoice with a new one instead of deleting it', function () {
    $original = Invoice::factory()->void()->create();
    $rectifying = Invoice::factory()->for($original->user)->create(['rectifies_invoice_id' => $original->id]);

    expect($original->status)->toBe(InvoiceStatus::Void)
        ->and($original->rectifiedBy->is($rectifying))->toBeTrue()
        ->and($rectifying->rectifies->is($original))->toBeTrue();
});

it('does not allow hard deleting a resource that has bookings', function () {
    $resource = Resource::factory()->create();

    Booking::factory()
        ->withSlot($resource, now()->addDay()->setTime(10, 0), now()->addDay()->setTime(11, 0))
        ->create();

    $resource->delete();
})->throws(QueryException::class);

it('seeds a consistent demo coworking without overlapping active bookings', function () {
    $this->seed();

    expect(User::count())->toBe(10)
        ->and(Space::count())->toBe(2)
        ->and(Resource::count())->toBe(20)
        ->and(Booking::count())->toBe(4)
        ->and(Invoice::count())->toBe(3)
        ->and(Payment::count())->toBe(1)
        ->and(Incident::count())->toBe(1);

    $slots = BookingResource::query()
        ->join('bookings', 'bookings.id', '=', 'booking_resource.booking_id')
        ->whereNotIn('bookings.status', [BookingStatus::Cancelled->value, BookingStatus::Expired->value])
        ->orderBy('booking_resource.resource_id')
        ->orderBy('booking_resource.start_at')
        ->get(['booking_resource.resource_id', 'booking_resource.start_at', 'booking_resource.end_at']);

    foreach ($slots->groupBy('resource_id') as $group) {
        $group = $group->values();

        foreach ($group as $i => $slot) {
            if ($i > 0) {
                expect($group[$i - 1]->end_at->lte($slot->start_at))->toBeTrue();
            }
        }
    }
});
