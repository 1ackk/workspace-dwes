<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Mail\BookingCancelledMail;
use App\Models\Booking;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía el correo de reserva cancelada. Va a la cola porque nace de una petición web: el usuario
 * no debe esperar al servidor de correo, y si este falla la cola lo reintenta.
 */
class SendBookingCancelledMailJob implements ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'mail';

    /** Intentos en total antes de darlo por fallido y guardarlo en failed_jobs. */
    public int $tries = 3;

    /**
     * Segundos de espera antes del 2.º y del 3.er intento.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60];

    /** Si la reserva se borra antes de procesar el job, se descarta sin error. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Booking $booking)
    {
        $this->onQueue(self::QUEUE);

        // No llega a la cola hasta el commit de la transacción de BookingService::cancel.
        $this->afterCommit();
    }

    public function handle(): void
    {
        // El correo solo es cierto si la reserva sigue cancelada y su titular existe.
        if ($this->booking->status !== BookingStatus::Cancelled || $this->booking->user === null) {
            return;
        }

        $this->booking->load('resources.space');

        Mail::to($this->booking->user)->send(new BookingCancelledMail($this->booking));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('No se pudo enviar el correo de reserva cancelada.', [
            'booking_id' => $this->booking->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
