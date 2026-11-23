<?php

namespace App\Services\Invoicing;

/**
 * Resultado de una ejecución de InvoiceGenerator::generate.
 */
readonly class InvoiceBatch
{
    /**
     * @param  list<string>  $invoiceNumbers  Números de las facturas creadas, en orden.
     * @param  list<string>  $warnings  Usuarios omitidos y por qué.
     */
    public function __construct(
        public array $invoiceNumbers,
        public int $bookings,
        public int $totalCents,
        public array $warnings,
    ) {}

    public function invoices(): int
    {
        return count($this->invoiceNumbers);
    }

    public function totalDecimal(): string
    {
        return InvoiceGenerator::toDecimal($this->totalCents);
    }
}
