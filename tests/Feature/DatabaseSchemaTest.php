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

it('has the reminder marks of the sprint 5 migrations, nullable', function () {
    expect(Schema::hasColumn('bookings', 'reminder_sent_at'))->toBeTrue()
        ->and(Schema::hasColumn('invoices', 'last_reminded_at'))->toBeTrue();

    $nullable = fn (string $table, string $column) => collect(Schema::getColumns($table))->firstWhere('name', $column)['nullable'];

    expect($nullable('bookings', 'reminder_sent_at'))->toBeTrue()
        ->and($nullable('invoices', 'last_reminded_at'))->toBeTrue()
        ->and(Booking::factory()->create()->reminder_sent_at)->toBeNull();
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
        ->and(Booking::count())->toBe(14)
        ->and(Invoice::count())->toBe(6)
        ->and(Payment::count())->toBe(4)
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

it('seeds four completed bookings of last month without invoice for invoices:generate', function () {
    $this->seed();

    $lastMonth = now()->subMonthNoOverflow();
    $billable = Booking::query()
        ->where('status', BookingStatus::Completed)
        ->whereNull('invoice_id')
        ->whereBetween('date', [$lastMonth->copy()->startOfMonth()->toDateString(), $lastMonth->copy()->endOfMonth()->toDateString()])
        ->get();

    expect($billable)->toHaveCount(4)
        ->and($billable->pluck('user_id')->unique())->toHaveCount(3)
        ->and($billable->sum(fn (Booking $booking) => (int) str_replace('.', '', $booking->total)))->toBe(8300);
});

it('numbers the seeded invoices one after another, so generated ones continue the series', function () {
    $this->seed();

    $year = now()->year;

    expect(Invoice::query()->orderBy('id')->pluck('number')->all())->toBe(
        array_map(fn (int $n) => sprintf('FAC-%d-%06d', $year, $n), range(1, 6)),
    );
});

it('has users.telegram_chat_id (A2): nullable and unique', function () {
    $column = collect(Schema::getColumns('users'))->firstWhere('name', 'telegram_chat_id');
    $unique = collect(Schema::getIndexes('users'))->first(fn (array $index) => $index['columns'] === ['telegram_chat_id']);

    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeTrue()
        ->and($unique)->not->toBeNull()
        ->and($unique['unique'])->toBeTrue();

    User::factory()->create()->forceFill(['telegram_chat_id' => '100000001'])->save();
    User::factory()->create()->forceFill(['telegram_chat_id' => '100000001'])->save();
})->throws(QueryException::class);

it('allows many users without Telegram', function () {
    User::factory()->count(3)->create();

    expect(User::query()->whereNull('telegram_chat_id')->count())->toBe(3);
});

it('seeds usuario1 as the only user with Telegram, with a fake chat id', function () {
    $this->seed();

    expect(User::query()->whereNotNull('telegram_chat_id')->pluck('telegram_chat_id', 'email')->all())
        ->toBe(['usuario1@workspace.test' => '100000001']);
});
