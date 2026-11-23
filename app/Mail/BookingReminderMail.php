<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\BookingResource;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Recordatorio de una reserva confirmada que empieza pronto. Lo envía SendBookingReminderMailJob,
 * de forma síncrona. La reserva debe traer cargadas sus franjas (slots) y sus recursos
 * (resources.space) para el resumen común.
 */
class BookingReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    /**
     * Inicio de la primera franja de la reserva.
     */
    public function startsAt(): CarbonInterface
    {
        return $this->booking->slots
            ->sortBy(fn (BookingResource $slot) => $slot->start_at->getTimestamp())
            ->firstOrFail()
            ->start_at;
    }

    /**
     * Horas que faltan, redondeando hacia arriba: a 30 minutos del inicio se dice "1 hora".
     */
    public function hoursLeft(): int
    {
        return max(1, (int) ceil(now()->diffInMinutes($this->startsAt()) / 60));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Recordatorio: tu reserva empieza el :date a las :time', [
                'date' => $this->startsAt()->format('d/m/Y'),
                'time' => $this->startsAt()->format('H:i'),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.bookings.reminder', with: [
            'startsAt' => $this->startsAt(),
            'hoursLeft' => $this->hoursLeft(),
        ]);
    }
}
