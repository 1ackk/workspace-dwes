<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\RoleName;
use App\Models\Booking;
use App\Models\Incident;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Resource;
use App\Models\Space;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración del coworking. Los usuarios con rol se identifican por
 * su email (admin@, contable@, recepcion@, responsable1@/2@, usuario1@..5@
 * workspace.test, contraseña "password"). Necesita los roles de RoleSeeder.
 */
class CoworkingSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create(['name' => 'Administrador', 'email' => 'admin@workspace.test'])
            ->assignRole(RoleName::Admin);
        User::factory()->create(['name' => 'Contable', 'email' => 'contable@workspace.test'])
            ->assignRole(RoleName::Accountant);
        User::factory()->create(['name' => 'Recepción', 'email' => 'recepcion@workspace.test'])
            ->assignRole(RoleName::Reception);

        $managers = collect([1, 2])->map(fn (int $n) => User::factory()->create([
            'name' => "Responsable {$n}",
            'email' => "responsable{$n}@workspace.test",
        ])->assignRole(RoleName::SpaceManager));

        $members = collect(range(1, 5))->map(fn (int $n) => User::factory()->create([
            'name' => "Miembro {$n}",
            'email' => "usuario{$n}@workspace.test",
        ])->assignRole(RoleName::Member));

        $spaces = collect([
            ['name' => 'Planta Baja', 'location' => 'Calle Mayor 1, planta baja'],
            ['name' => 'Planta Alta', 'location' => 'Calle Mayor 1, planta alta'],
        ])->map(fn (array $data, int $i) => Space::factory()->create([
            ...$data,
            'manager_id' => $managers[$i]->id,
        ]));

        foreach ($spaces as $space) {
            Resource::factory()->desk()->count(6)->sequence(
                fn ($sequence) => ['name' => 'Puesto '.($sequence->index + 1)]
            )->create(['space_id' => $space->id]);

            Resource::factory()->room()->count(2)->sequence(
                fn ($sequence) => ['name' => 'Sala '.($sequence->index + 1)]
            )->create(['space_id' => $space->id]);

            Resource::factory()->meetingRoom()->create(['space_id' => $space->id, 'name' => 'Sala de reuniones']);
            Resource::factory()->projector()->create(['space_id' => $space->id, 'name' => 'Proyector']);
        }

        // Recargos generales del coworking: tarde y fin de semana.
        Tariff::factory()->afternoon()->create();
        Tariff::factory()->weekend()->create();

        $firstSpace = $spaces->first();
        $meetingRoom = $firstSpace->resources()->where('name', 'Sala de reuniones')->firstOrFail();
        $projector = $firstSpace->resources()->where('name', 'Proyector')->firstOrFail();
        $desk = $firstSpace->resources()->where('name', 'Puesto 1')->firstOrFail();
        $room = $firstSpace->resources()->where('name', 'Sala 1')->firstOrFail();

        // Tarifa de un recurso concreto: base promocional del Puesto 1 durante un mes.
        Tariff::factory()->for($desk)->create([
            'name' => 'Promoción Puesto 1',
            'price_per_hour' => 2.50,
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addMonth()->toDateString(),
        ]);

        $day = now()->addDay()->startOfDay();

        // Reserva multirrecurso confirmada (sala + proyector, misma franja) con factura pendiente.
        $multi = Booking::factory()->confirmed()
            ->for($members[0], 'user')
            ->withSlot($meetingRoom, $day->copy()->setTime(10, 0), $day->copy()->setTime(12, 0))
            ->withSlot($projector, $day->copy()->setTime(10, 0), $day->copy()->setTime(12, 0))
            ->create(['date' => $day->toDateString()]);

        $pendingInvoice = Invoice::factory()
            ->for($members[0], 'user')
            ->create(['amount' => $multi->refresh()->total]);
        $multi->update(['invoice_id' => $pendingInvoice->id]);

        // Reserva confirmada con factura pagada y su pago.
        $paidBooking = Booking::factory()->confirmed()
            ->for($members[1], 'user')
            ->withSlot($desk, $day->copy()->setTime(9, 0), $day->copy()->setTime(13, 0))
            ->create(['date' => $day->toDateString()]);

        $paidInvoice = Invoice::factory()->paid()
            ->for($members[1], 'user')
            ->create(['amount' => $paidBooking->refresh()->total]);
        $paidBooking->update(['invoice_id' => $paidInvoice->id]);
        Payment::factory()->create([
            'invoice_id' => $paidInvoice->id,
            'booking_id' => $paidBooking->id,
            'amount' => $paidInvoice->amount,
        ]);

        // Reserva pendiente de pago con la retención aún vigente.
        Booking::factory()
            ->for($members[2], 'user')
            ->withSlot($room, $day->copy()->setTime(16, 0), $day->copy()->setTime(18, 0))
            ->create(['date' => $day->toDateString()]);

        // Reserva cancelada sobre la misma franja que la multirrecurso: no la bloquea.
        Booking::factory()->cancelled()
            ->for($members[3], 'user')
            ->withSlot($meetingRoom, $day->copy()->setTime(10, 0), $day->copy()->setTime(12, 0))
            ->create(['date' => $day->toDateString()]);

        // Facturas vencidas para el scope overdueInvoices. Cada reserva usa un recurso, un día y
        // una hora propios: el seeder no pasa por el servicio de reservas y nada impediría un solape.
        $overdueInvoice = function (User $member, string $resourceName, int $daysAgo, int $hour) use ($firstSpace): Invoice {
            $start = now()->subDays($daysAgo)->setTime($hour, 0);
            $resource = $firstSpace->resources()->where('name', $resourceName)->firstOrFail();

            $booking = Booking::factory()->for($member, 'user')
                ->withSlot($resource, $start, $start->copy()->addHours(3))
                ->create(['status' => BookingStatus::Completed, 'expires_at' => null]);

            $invoice = Invoice::factory()->overdue()->for($member, 'user')->create([
                'period_start' => $start->copy()->startOfMonth()->toDateString(),
                'period_end' => $start->copy()->endOfMonth()->toDateString(),
                'amount' => $booking->refresh()->total,
            ]);
            $booking->update(['invoice_id' => $invoice->id]);

            return $invoice;
        };

        // Vencida sin ningún pago: entra por el total.
        $overdueInvoice($members[4], 'Puesto 2', 10, 9);

        // Vencida con un pago parcial completado: entra por la mitad restante.
        $partlyPaid = $overdueInvoice($members[2], 'Puesto 3', 12, 11);
        Payment::factory()->create([
            'invoice_id' => $partlyPaid->id,
            'amount' => round((float) $partlyPaid->amount / 2, 2),
        ]);

        // Vencida con un pago fallido: el pago no cuenta y entra por el total.
        $failedPayment = $overdueInvoice($members[3], 'Puesto 4', 14, 14);
        Payment::factory()->failed()->create([
            'invoice_id' => $failedPayment->id,
            'amount' => $failedPayment->amount,
        ]);

        // Caso límite: pagada del todo pero aún marcada como pendiente. No entra (saldo 0).
        $fullyPaid = $overdueInvoice($members[4], 'Sala 2', 16, 16);
        Payment::factory()->create([
            'invoice_id' => $fullyPaid->id,
            'amount' => $fullyPaid->amount,
        ]);

        Incident::factory()->create([
            'user_id' => $members[0]->id,
            'resource_id' => $projector->id,
            'booking_id' => $multi->id,
            'title' => 'El proyector no enciende',
        ]);
    }
}
