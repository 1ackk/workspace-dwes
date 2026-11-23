<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo al titular de una reserva cancelada. Lo envía SendBookingCancelledMailJob, en la cola.
 */
class BookingCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Reserva cancelada del :date', ['date' => $this->booking->date->format('d/m/Y')]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.bookings.cancelled');
    }
}
