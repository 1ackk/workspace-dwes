<?php

namespace App\Services\Reminders;

use App\Enums\BookingStatus;
use App\Events\BookingStartingSoon;
use App\Events\InvoiceOverdue;
use App\Models\Booking;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Recordatorios programados: reservas que empiezan pronto y facturas vencidas. Los comandos
 * bookings:remind-upcoming e invoices:send-overdue-reminders solo llaman aquí y escriben el
 * resultado, igual que bookings:release-unpaid con BookingService.
 *
 * Mismo esquema que BookingService::releaseUnpaid: se leen los ids de las candidatas, cada una se
 * marca con un UPDATE que repite la regla completa (incluida "aún sin marcar") y el evento se lanza
 * solo si ese UPDATE cambió la fila. Así otra ejecución simultánea no repite el aviso, y un fallo
 * al avisar de una fila se registra sin impedir avisar a las demás.
 */
class ReminderService
{
    /**
     * Marca y recuerda las reservas confirmadas cuya primera franja empieza en (ahora, ahora + horas].
     * Devuelve cuántas se han marcado.
     */
    public function remindUpcoming(CarbonInterface $now, int $hours): int
    {
        return $this->markEach(
            fn () => $this->upcomingAt($now, $hours),
            fn (Builder $booking) => $booking->update(['reminder_sent_at' => $now]),
            fn (int $id) => new BookingStartingSoon(Booking::query()->findOrFail($id)),
        );
    }

    public function countUpcoming(CarbonInterface $now, int $hours): int
    {
        return $this->upcomingAt($now, $hours)->count();
    }

    /**
     * Marca y avisa de las facturas vencidas a fecha $asOf con saldo pendiente que no se hayan
     * avisado en los últimos $everyDays días. Devuelve cuántas se han marcado.
     */
    public function remindOverdue(CarbonInterface $now, CarbonInterface $asOf, int $everyDays): int
    {
        return $this->markEach(
            fn () => $this->overdueToRemind($now, $asOf, $everyDays),
            // toBase(): solo cambia last_reminded_at; ni el estado, ni el importe, ni updated_at.
            fn (Builder $invoice) => $invoice->toBase()->update(['last_reminded_at' => $now]),
            fn (int $id) => new InvoiceOverdue(Invoice::query()->findOrFail($id), $asOf->toDateString()),
        );
    }

    public function countOverdue(CarbonInterface $now, CarbonInterface $asOf, int $everyDays): int
    {
        return $this->overdueToRemind($now, $asOf, $everyDays)->count();
    }

    /**
     * Confirmadas, sin recordar, con alguna franja en la ventana y ninguna ya empezada: es decir,
     * su primera franja empieza en (ahora, ahora + horas]. SoftDeletes deja fuera las borradas.
     *
     * @return Builder<Booking>
     */
    private function upcomingAt(CarbonInterface $now, int $hours): Builder
    {
        $until = $now->copy()->addHours($hours);

        return Booking::query()
            ->where('bookings.status', BookingStatus::Confirmed)
            ->whereNull('bookings.reminder_sent_at')
            ->whereHas('slots', fn (Builder $slots) => $slots->where('start_at', '>', $now)->where('start_at', '<=', $until))
            ->whereDoesntHave('slots', fn (Builder $slots) => $slots->where('start_at', '<=', $now));
    }

    /**
     * El scope overdueInvoices con una condición más encadenada (no es un scope nuevo): nunca
     * avisada, o avisada antes de ahora − $everyDays días.
     *
     * @return Builder<Invoice>
     */
    private function overdueToRemind(CarbonInterface $now, CarbonInterface $asOf, int $everyDays): Builder
    {
        $remindedBefore = $now->copy()->subDays($everyDays);

        return Invoice::query()
            ->overdueInvoices($asOf)
            ->where(fn (Builder $query) => $query
                ->whereNull('invoices.last_reminded_at')
                ->orWhere('invoices.last_reminded_at', '<', $remindedBefore));
    }

    /**
     * @template TModel of Booking|Invoice
     *
     * @param  Closure(): Builder<TModel>  $candidates
     * @param  Closure(Builder<TModel>): int  $mark
     * @param  Closure(int): object  $event
     */
    private function markEach(Closure $candidates, Closure $mark, Closure $event): int
    {
        $marked = [];

        foreach ($candidates()->pluck($candidates()->qualifyColumn('id')) as $id) {
            if ($mark($candidates()->whereKey($id)) === 1) {
                $marked[] = (int) $id;
            }
        }

        foreach ($marked as $id) {
            try {
                event($event($id));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return count($marked);
    }
}
