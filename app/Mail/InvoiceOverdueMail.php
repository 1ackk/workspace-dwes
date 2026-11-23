<?php

namespace App\Mail;

use App\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso de factura vencida con saldo pendiente. La factura debe venir del scope overdueInvoices,
 * que añade la columna balance (el saldo de ahora).
 */
class InvoiceOverdueMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice, public CarbonInterface $asOf) {}

    /**
     * Mismo cálculo que invoices:list-overdue.
     */
    public function daysLate(): int
    {
        return (int) round($this->invoice->due_at?->diffInDays($this->asOf) ?? 0);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Factura :number vencida: saldo pendiente', ['number' => $this->invoice->number]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoices.overdue', with: [
            'daysLate' => $this->daysLate(),
        ]);
    }
}
