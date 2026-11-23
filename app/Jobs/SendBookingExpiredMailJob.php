<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Mail\BookingExpiredMail;
use App\Models\Booking;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía el correo de reserva caducada. NO va a la cola: el listener lo ejecuta con dispatchSync
 * dentro de bookings:release-unpaid, donde nadie espera la respuesta y son pocas por minuto.
 *
 * Implementa ShouldQueue igualmente para que dispatchSync lo pase por la conexión "sync" de
 * Laravel, que respeta afterCommit() y llama a failed() si el envío falla. $tries y $backoff
 * se mantienen por coherencia con los otros jobs, pero en síncrono Laravel lo ejecuta una sola
 * vez: no hay reintentos.
 */
class SendBookingExpiredMailJob implements ShouldQueue
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
        // El correo solo es cierto si la reserva sigue caducada y su titular existe.
        if ($this->booking->status !== BookingStatus::Expired || $this->booking->user === null) {
            return;
        }

        $this->booking->load('resources.space');

        Mail::to($this->booking->user)->send(new BookingExpiredMail($this->booking));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('No se pudo enviar el correo de reserva caducada.', [
            'booking_id' => $this->booking->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
