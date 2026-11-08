<?php

namespace Database\Seeders;

use App\Enums\InvoiceStatus;
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
 * workspace.test, contraseña "password"); los roles se asignan en el sprint 3
 * con laravel-permission.
 */
class CoworkingSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create(['name' => 'Administrador', 'email' => 'admin@workspace.test']);
        User::factory()->create(['name' => 'Contable', 'email' => 'contable@workspace.test']);
        User::factory()->create(['name' => 'Recepción', 'email' => 'recepcion@workspace.test']);

        $managers = collect([1, 2])->map(fn (int $n) => User::factory()->create([
            'name' => "Responsable {$n}",
            'email' => "responsable{$n}@workspace.test",
        ]));

        $members = collect(range(1, 5))->map(fn (int $n) => User::factory()->create([
            'name' => "Miembro {$n}",
            'email' => "usuario{$n}@workspace.test",
        ]));

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

        // Reglas de tarifa generales del coworking y una específica.
        Tariff::factory()->afternoon()->create();
        Tariff::factory()->weekend()->create();

        $firstSpace = $spaces->first();
        $meetingRoom = $firstSpace->resources()->where('name', 'Sala de reuniones')->firstOrFail();
        $projector = $firstSpace->resources()->where('name', 'Proyector')->firstOrFail();
        $desk = $firstSpace->resources()->where('name', 'Puesto 1')->firstOrFail();
        $room = $firstSpace->resources()->where('name', 'Sala 1')->firstOrFail();

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

        // Factura vencida sin pagar.
        Invoice::factory()->overdue()
            ->for($members[4], 'user')
            ->create(['amount' => 45.00, 'status' => InvoiceStatus::Pending]);

        Incident::factory()->create([
            'user_id' => $members[0]->id,
            'resource_id' => $projector->id,
            'booking_id' => $multi->id,
            'title' => 'El proyector no enciende',
        ]);
    }
}
