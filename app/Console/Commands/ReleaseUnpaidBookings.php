<?php

namespace App\Console\Commands;

use App\Services\Booking\BookingService;
use Illuminate\Console\Command;

class ReleaseUnpaidBookings extends Command
{
    protected $signature = 'bookings:release-unpaid {--dry-run : Dice cuántas cambiaría sin tocar nada}';

    protected $description = 'Marca como caducadas las reservas pendientes cuya retención ya venció';

    public function handle(BookingService $bookings): int
    {
        $now = now();

        if ($this->option('dry-run')) {
            $this->info(__('Simulación: reservas que se liberarían: :count. No se ha cambiado nada.', [
                'count' => $bookings->countUnpaid($now),
            ]));

            return self::SUCCESS;
        }

        $this->info(__('Reservas liberadas: :count.', ['count' => $bookings->releaseUnpaid($now)]));

        return self::SUCCESS;
    }
}
