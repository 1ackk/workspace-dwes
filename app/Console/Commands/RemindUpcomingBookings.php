<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesBoundedInteger;
use App\Services\Reminders\ReminderService;
use Illuminate\Console\Command;

class RemindUpcomingBookings extends Command
{
    use ParsesBoundedInteger;

    private const int MIN_HOURS = 1;

    private const int MAX_HOURS = 168;

    protected $signature = 'bookings:remind-upcoming
        {--hours=24 : Ventana en horas, de 1 a 168: recuerda las reservas que empiezan en ese plazo}
        {--dry-run : Dice cuántas recordaría sin marcar ni enviar nada}';

    protected $description = 'Envía un recordatorio de las reservas confirmadas que empiezan pronto';

    public function handle(ReminderService $reminders): int
    {
        $option = $this->option('hours');
        $hours = $this->parseBoundedInteger($option, self::MIN_HOURS, self::MAX_HOURS);

        if ($hours === null) {
            return $this->invalidInteger('hours', $option, self::MIN_HOURS, self::MAX_HOURS);
        }

        $now = now();

        if ($this->option('dry-run')) {
            $this->info(__('Simulación: reservas que se recordarían en las próximas :hours horas: :count. No se ha enviado nada.', [
                'hours' => $hours,
                'count' => $reminders->countUpcoming($now, $hours),
            ]));

            return self::SUCCESS;
        }

        $this->info(__('Recordatorios enviados: :count.', ['count' => $reminders->remindUpcoming($now, $hours)]));

        return self::SUCCESS;
    }
}
