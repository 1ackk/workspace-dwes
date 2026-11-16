<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesDays;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

class ListOverdueInvoices extends Command
{
    use ParsesDays;

    protected $signature = 'invoices:list-overdue {--as-of= : Fecha de referencia AAAA-MM-DD; por defecto, hoy}';

    protected $description = 'Lista las facturas vencidas con saldo por cobrar';

    public function handle(): int
    {
        $option = $this->option('as-of');
        $asOf = $option === null ? today() : $this->parseDay($this->asText($option));

        if ($asOf === null) {
            return $this->invalidDay($this->asText($option));
        }

        // Una factura sobrevive a su usuario, aunque este se haya borrado de forma lógica.
        $invoices = Invoice::query()
            ->overdueInvoices($asOf)
            ->with(['user' => fn ($query) => $query->withTrashed()])
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            $this->info(__('No hay facturas vencidas a fecha :date.', ['date' => $asOf->format('d/m/Y')]));

            return self::SUCCESS;
        }

        $this->table(
            [__('Número'), __('Email'), __('Vencimiento'), __('Importe'), __('Saldo'), __('Días de retraso')],
            $invoices->map(fn (Invoice $invoice) => $this->row($invoice, $asOf))->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return list<string|int>
     */
    private function row(Invoice $invoice, CarbonInterface $asOf): array
    {
        return [
            $invoice->number,
            $invoice->user->email,
            $invoice->due_at?->format('d/m/Y') ?? '',
            $invoice->amount,
            $invoice->balance,
            (int) round($invoice->due_at?->diffInDays($asOf) ?? 0),
        ];
    }
}
