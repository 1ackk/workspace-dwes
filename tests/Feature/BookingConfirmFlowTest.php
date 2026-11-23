<?php

use App\Enums\BookingStatus;
use App\Enums\RoleName;
use App\Jobs\SendBookingConfirmedMailJob;
use App\Mail\BookingConfirmedMail;
use App\Models\Resource;
use App\Models\Space;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Pricing\Slot;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);

    $this->owner = userWithRole(RoleName::Member);
    $this->owner->update(['name' => 'Ana Pérez']);

    $space = Space::factory()->create(['name' => 'Planta Baja']);
    $this->resource = Resource::factory()->for($space)->create(['name' => 'Sala Azul', 'hourly_rate' => 12]);

    // Reserva pendiente creada como en la aplicación: con el servicio, retención de 15 minutos.
    $this->booking = app(BookingService::class)->createPending($this->owner, [
        new Slot($this->resource, Carbon::parse('2026-12-02 10:00'), Carbon::parse('2026-12-02 12:00')),
    ]);
});

describe('http', function () {
    test('the owner sees the simulated payment button on a pending booking', function () {
        $this->actingAs($this->owner)
            ->get(route('my-bookings.show', $this->booking))
            ->assertOk()
            ->assertSee(route('my-bookings.confirm', $this->booking))
            ->assertSee('Pagar (simulado)')
            ->assertSee('09:15');
    });

    test('the owner confirms from the button and sees the result', function () {
        Queue::fake();

        $this->actingAs($this->owner)
            ->post(route('my-bookings.confirm', $this->booking))
            ->assertRedirect(route('my-bookings.show', $this->booking))
            ->assertSessionHas('status');

        expect($this->booking->fresh()->status)->toBe(BookingStatus::Confirmed);
        Queue::assertPushedOn('mail', SendBookingConfirmedMailJob::class);
    });

    test('the button disappears once confirmed', function () {
        app(BookingService::class)->confirm($this->booking);

        $this->actingAs($this->owner)
            ->get(route('my-bookings.show', $this->booking))
            ->assertOk()
            ->assertDontSee('Pagar (simulado)');
    });

    test('an expired hold comes back with the reason and nothing changes', function () {
        Queue::fake();
        Carbon::setTestNow('2026-12-01 09:20:00');

        $this->actingAs($this->owner)
            ->post(route('my-bookings.confirm', $this->booking))
            ->assertRedirect(route('my-bookings.show', $this->booking))
            ->assertSessionHas('error', 'El tiempo para pagar la reserva ha terminado. Haz una reserva nueva.');

        expect($this->booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
        Queue::assertNothingPushed();
    });

    test('nobody else can confirm the booking', function (RoleName $role) {
        $this->actingAs(userWithRole($role))
            ->post(route('my-bookings.confirm', $this->booking))
            ->assertForbidden();

        expect($this->booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
    })->with([
        'otro usuario' => RoleName::Member,
        'recepción' => RoleName::Reception,
        'responsable' => RoleName::SpaceManager,
        'contable' => RoleName::Accountant,
        'administrador' => RoleName::Admin,
    ]);

    test('reception can view the booking but gets no payment button', function () {
        $this->actingAs(userWithRole(RoleName::Reception))
            ->get(route('my-bookings.show', $this->booking))
            ->assertOk()
            ->assertDontSee('Pagar (simulado)');
    });

    test('a guest goes to the login', function () {
        $this->post(route('my-bookings.confirm', $this->booking))->assertRedirect(route('login'));
    });
});

describe('full flow with the database queue', function () {
    test('confirming queues the job, the worker processes it and the mail is sent', function () {
        config(['queue.default' => 'database']);
        Mail::fake();

        $this->actingAs($this->owner)->post(route('my-bookings.confirm', $this->booking))->assertRedirect();

        // El correo no sale en la petición: queda un job en la cola "mail".
        Mail::assertNothingSent();
        expect(DB::table('jobs')->where('queue', 'mail')->count())->toBe(1);

        Artisan::call('queue:work', ['--queue' => 'mail', '--stop-when-empty' => true]);

        expect(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);

        Mail::assertSentCount(1);
        Mail::assertSent(BookingConfirmedMail::class, function (BookingConfirmedMail $mail): bool {
            $html = $mail->render();

            return $mail->hasTo($this->owner->email)
                && $mail->booking->is($this->booking)
                && str_contains($html, 'Ana Pérez')
                && str_contains($html, 'Sala Azul')
                && str_contains($html, 'Planta Baja')
                && str_contains($html, '02/12/2026 10:00 – 12:00')
                && str_contains($html, '24,00 €');
        });
    });

    test('the mail has a Spanish subject with the date', function () {
        $mail = new BookingConfirmedMail($this->booking->load(['user', 'resources.space']));

        $mail->assertHasSubject('Reserva confirmada para el 02/12/2026');
        $mail->assertDontSeeInHtml($this->owner->email);
    });
});

describe('job', function () {
    test('does not send the mail if the booking was cancelled before it ran', function () {
        Queue::fake();
        Mail::fake();
        app(BookingService::class)->confirm($this->booking);
        $this->booking->update(['status' => BookingStatus::Cancelled]);

        (new SendBookingConfirmedMailJob($this->booking->fresh()))->handle();

        Mail::assertNothingSent();
    });

    test('does not send the mail if the user no longer exists', function () {
        Queue::fake();
        Mail::fake();
        $booking = app(BookingService::class)->confirm($this->booking);
        User::query()->whereKey($this->owner->id)->delete();

        (new SendBookingConfirmedMailJob($booking->fresh()))->handle();

        Mail::assertNothingSent();
    });

    test('logs the failure once the attempts are exhausted', function () {
        Log::shouldReceive('error')
            ->once()
            ->with('No se pudo enviar el correo de reserva confirmada.', ['booking_id' => $this->booking->id, 'error' => 'SMTP caído']);

        (new SendBookingConfirmedMailJob($this->booking))->failed(new RuntimeException('SMTP caído'));
    });
});
