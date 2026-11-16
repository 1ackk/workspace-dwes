<?php

namespace App\Console\Commands;

use App\Services\Booking\BookingService;
use Illuminate\Console\Command;

class CompletePastBookings extends Command
{
    protected $signature = 'bookings:complete-past {--dry-run : Dice cuántas cambiaría sin tocar nada}';

    protected $description = 'Marca como completadas las reservas confirmadas cuyas franjas ya terminaron';

    public function handle(BookingService $bookings): int
    {
        $now = now();

        if ($this->option('dry-run')) {
            $this->info(__('Simulación: reservas que se completarían: :count. No se ha cambiado nada.', [
                'count' => $bookings->countPast($now),
            ]));

            return self::SUCCESS;
        }

        $this->info(__('Reservas completadas: :count.', ['count' => $bookings->completePast($now)]));

        return self::SUCCESS;
    }
}
