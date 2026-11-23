<?php

namespace App\Services\Invoicing;

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Genera las facturas de un mes ya terminado: una por usuario con reservas completadas y sin
 * facturar de ese mes. El comando invoices:generate solo llama aquí y escribe el resultado.
 *
 * Cada factura se crea en su propia transacción. La primera consulta bloquea las reservas del
 * usuario (en orden de id), así que dos ejecuciones simultáneas se esperan en vez de facturar dos
 * veces lo mismo, y como recorren a los usuarios en el mismo orden no se forman interbloqueos.
 */
class InvoiceGenerator
{
    /** Intentos por factura si otra ejecución se queda antes con el mismo número. */
    private const int NUMBER_ATTEMPTS = 3;

    /**
     * Lo que generaría, sin escribir nada: por usuario, su número de reservas y el importe.
     *
     * @return list<array{user: ?User, bookings: int, cents: int}>
     */
    public function preview(CarbonInterface $month, ?int $userId = null): array
    {
        $byUser = $this->billable($month, $userId)
            ->with(['user' => fn ($query) => $query->withTrashed()])
            ->orderBy('user_id')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        $rows = [];

        foreach ($byUser as $bookings) {
            $rows[] = [
                'user' => $bookings->firstOrFail()->user,
                'bookings' => $bookings->count(),
                'cents' => $this->sumCents($bookings),
            ];
        }

        return $rows;
    }

    public function generate(CarbonInterface $month, ?int $userId = null): InvoiceBatch
    {
        // Fuera de toda transacción: solo decide a quién facturar y en qué orden.
        $userIds = $this->billable($month, $userId)->distinct()->orderBy('user_id')->pluck('user_id');

        $numbers = [];
        $bookings = 0;
        $cents = 0;
        $warnings = [];

        foreach ($userIds as $id) {
            try {
                $invoice = $this->invoiceUserWithRetries($month, (int) $id);
            } catch (BookingsChangedException $exception) {
                $warnings[] = $exception->getMessage();

                continue;
            }

            if ($invoice === null) {
                continue;
            }

            if ($invoice === false) {
                $warnings[] = __('No se pudo obtener un número de factura libre para el usuario :user tras :attempts intentos. Se ha omitido.', [
                    'user' => $id,
                    'attempts' => self::NUMBER_ATTEMPTS,
                ]);

                continue;
            }

            $numbers[] = $invoice['number'];
            $bookings += $invoice['bookings'];
            $cents += $invoice['cents'];
        }

        return new InvoiceBatch($numbers, $bookings, $cents, $warnings);
    }

    /**
     * "12.50" → 1250. Los importes llegan con el cast decimal:2, siempre con dos decimales.
     */
    public static function toCents(string $decimal): int
    {
        return (int) str_replace('.', '', $decimal);
    }

    /**
     * 1250 → "12.50", sin pasar por float.
     */
    public static function toDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * Reservas completadas, sin factura y con fecha dentro del mes (primer y último día incluidos).
     * SoftDeletes deja fuera las borradas; el usuario puede estar borrado y se factura igual.
     *
     * @return Builder<Booking>
     */
    private function billable(CarbonInterface $month, ?int $userId): Builder
    {
        return Booking::query()
            ->where('bookings.status', BookingStatus::Completed)
            ->whereNull('bookings.invoice_id')
            // whereDate y no whereBetween: SQLite guarda la fecha como "2026-11-30 00:00:00", y
            // comparada como texto con "2026-11-30" dejaría fuera el último día del mes.
            ->whereDate('bookings.date', '>=', $month->copy()->startOfMonth()->toDateString())
            ->whereDate('bookings.date', '<=', $month->copy()->endOfMonth()->toDateString())
            ->when($userId !== null, fn (Builder $query) => $query->where('bookings.user_id', $userId));
    }

    /**
     * Repite la transacción del usuario si el número de factura ya lo ha cogido otra ejecución.
     * Devuelve el número, las reservas y el importe de la factura creada; null si ya no quedaban
     * reservas, o false si se agotaron los intentos.
     *
     * @return array{number: string, bookings: int, cents: int}|null|false
     */
    private function invoiceUserWithRetries(CarbonInterface $month, int $userId): array|null|false
    {
        for ($attempt = 1; $attempt <= self::NUMBER_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(fn () => $this->invoiceUser($month, $userId));
            } catch (UniqueConstraintViolationException) {
                // Otra ejecución creó una factura con el mismo número entre medias: se reintenta
                // la transacción completa, que vuelve a calcular el número siguiente.
            }
        }

        return false;
    }

    /**
     * @return array{number: string, bookings: int, cents: int}|null
     */
    private function invoiceUser(CarbonInterface $month, int $userId): ?array
    {
        // Primera consulta de la transacción: en MySQL, una lectura previa al bloqueo fijaría la foto
        // de datos y, tras esperar a otra ejecución, no veríamos que ya facturó estas reservas.
        $locked = $this->billable($month, $userId)->orderBy('id')->lockForUpdate()->get(['id', 'total']);

        if ($locked->isEmpty()) {
            return null;
        }

        $cents = $this->sumCents($locked);
        $periodEnd = $month->copy()->endOfMonth();

        $invoice = Invoice::create([
            'user_id' => $userId,
            'number' => $this->nextNumber(now()),
            'period_start' => $month->copy()->startOfMonth()->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'amount' => self::toDecimal($cents),
            'status' => InvoiceStatus::Pending,
            'issued_at' => now(),
            'due_at' => $periodEnd->copy()->addDays(config()->integer('invoicing.due_days'))->toDateString(),
        ]);

        $ids = $locked->modelKeys();

        $changed = Booking::query()
            ->whereKey($ids)
            ->whereNull('invoice_id')
            ->where('status', BookingStatus::Completed)
            ->update(['invoice_id' => $invoice->id]);

        if ($changed !== count($ids)) {
            throw BookingsChangedException::for($userId, count($ids), $changed);
        }

        return ['number' => $invoice->number, 'bookings' => $changed, 'cents' => $cents];
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function sumCents(Collection $bookings): int
    {
        return $bookings->sum(fn (Booking $booking) => self::toCents($booking->total));
    }

    /**
     * Siguiente número del año de emisión: FAC-2026-000007. Cuenta también las facturas borradas,
     * que siguen ocupando su número en el índice único. Si dos ejecuciones calculan el mismo número
     * a la vez, el índice único rechaza la segunda y invoiceUserWithRetries la repite.
     */
    private function nextNumber(CarbonInterface $issuedAt): string
    {
        $prefix = 'FAC-'.$issuedAt->year.'-';

        $last = Invoice::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->pluck('number')
            ->map(fn (string $number) => substr($number, strlen($prefix)))
            ->filter(fn (string $suffix) => ctype_digit($suffix))
            ->map(fn (string $suffix) => (int) $suffix)
            ->max() ?? 0;

        return $prefix.sprintf('%06d', $last + 1);
    }
}
