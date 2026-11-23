<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesBoundedInteger;
use App\Models\User;
use App\Rules\FinishedMonth;
use App\Services\Invoicing\InvoiceGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class GenerateInvoices extends Command
{
    use ParsesBoundedInteger;

    protected $signature = 'invoices:generate
        {month : Mes ya terminado, AAAA-MM}
        {--user= : Factura solo a este usuario (id)}
        {--dry-run : Muestra las facturas que crearía sin escribir nada}';

    protected $description = 'Genera las facturas de un mes terminado: una por usuario con reservas completadas sin facturar';

    public function handle(InvoiceGenerator $generator): int
    {
        $monthText = is_string($this->argument('month')) ? $this->argument('month') : '';
        $validator = Validator::make(['month' => $monthText], ['month' => ['required', new FinishedMonth]]);
        $month = FinishedMonth::parse($monthText);

        if ($validator->fails() || $month === null) {
            $this->error(__('Mes no válido: ":value". :reason', [
                'value' => $monthText,
                'reason' => $validator->errors()->first('month'),
            ]));

            return self::FAILURE;
        }

        $userId = null;

        if ($this->option('user') !== null) {
            $userId = $this->parseBoundedInteger($this->option('user'), 1, PHP_INT_MAX);

            // Un usuario borrado lógicamente también se factura: el histórico no se pierde.
            if ($userId === null || ! User::withTrashed()->whereKey($userId)->exists()) {
                $this->error(__('No existe ningún usuario con id ":value".', [
                    'value' => is_string($this->option('user')) ? $this->option('user') : '',
                ]));

                return self::FAILURE;
            }
        }

        $period = $month->format('m/Y');

        if ($this->option('dry-run')) {
            $preview = $generator->preview($month, $userId);

            if ($preview === []) {
                $this->info(__('No hay reservas que facturar en :period.', ['period' => $period]));

                return self::SUCCESS;
            }

            $this->table(
                [__('Usuario'), __('Reservas'), __('Importe')],
                array_map(fn (array $row) => [$row['user']?->email, $row['bookings'], InvoiceGenerator::toDecimal($row['cents'])], $preview),
            );
            $this->info(__('Simulación: se crearían :count facturas. No se ha escrito nada.', ['count' => count($preview)]));

            return self::SUCCESS;
        }

        $batch = $generator->generate($month, $userId);

        foreach ($batch->warnings as $warning) {
            $this->warn($warning);
        }

        if ($batch->invoices() === 0) {
            $this->info(__('No hay reservas que facturar en :period.', ['period' => $period]));

            return self::SUCCESS;
        }

        $this->info(__('Facturas generadas en :period: :invoices. Reservas facturadas: :bookings. Importe total: :total €.', [
            'period' => $period,
            'invoices' => $batch->invoices(),
            'bookings' => $batch->bookings,
            'total' => $batch->totalDecimal(),
        ]));

        return self::SUCCESS;
    }
}
