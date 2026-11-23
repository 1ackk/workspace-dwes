<?php

namespace App\Jobs;

use App\Mail\InvoiceOverdueMail;
use App\Models\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía el aviso de factura vencida. Va a la cola porque es un envío masivo: cada correo se
 * reintenta por separado y un fallo no corta el resto.
 */
class SendInvoiceOverdueMailJob implements ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'mail';

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Invoice $invoice, public string $asOf)
    {
        $this->onQueue(self::QUEUE);
        $this->afterCommit();
    }

    public function handle(): void
    {
        // Un titular borrado lógicamente no recibe avisos (la relación devuelve null).
        if ($this->invoice->user === null) {
            return;
        }

        // Entre el comando y el worker la factura puede haberse pagado: se vuelve a preguntar al
        // scope, con el mismo corte, y se usa el saldo de ahora.
        $current = Invoice::query()
            ->whereKey($this->invoice->id)
            ->overdueInvoices(Carbon::parse($this->asOf))
            ->first();

        if ($current === null) {
            return;
        }

        $current->setRelation('user', $this->invoice->user);

        Mail::to($this->invoice->user)->send(new InvoiceOverdueMail($current, Carbon::parse($this->asOf)));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('No se pudo enviar el aviso de factura vencida.', [
            'invoice_id' => $this->invoice->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
