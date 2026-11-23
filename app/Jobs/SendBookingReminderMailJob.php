<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Mail\BookingReminderMail;
use App\Models\Booking;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía el recordatorio de reserva próxima. NO va a la cola: el listener lo ejecuta con
 * dispatchSync dentro de bookings:remind-upcoming. Si el worker estuviera parado, un recordatorio
 * en cola llegaría tarde y dejaría de tener sentido.
 *
 * Implementa ShouldQueue para que dispatchSync lo pase por la conexión "sync", que respeta
 * afterCommit() y llama a failed(). En síncrono no hay reintentos: $tries y $backoff se mantienen
 * por coherencia con los otros jobs.
 */
class SendBookingReminderMailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Booking $booking)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        // Si se canceló después de marcarla, el recordatorio ya no tiene sentido.
        if ($this->booking->status !== BookingStatus::Confirmed || $this->booking->user === null) {
            return;
        }

        $this->booking->load(['slots', 'resources.space']);

        Mail::to($this->booking->user)->send(new BookingReminderMail($this->booking));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('No se pudo enviar el recordatorio de reserva.', [
            'booking_id' => $this->booking->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
