<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo al usuario con el resumen de su reserva confirmada. No implementa ShouldQueue: lo envía
 * SendBookingConfirmedMailJob, que ya está en la cola.
 */
class BookingConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Reserva confirmada para el :date', ['date' => $this->booking->date->format('d/m/Y')]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.bookings.confirmed');
    }
}
