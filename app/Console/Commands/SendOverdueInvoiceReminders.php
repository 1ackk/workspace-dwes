<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesBoundedInteger;
use App\Console\Commands\Concerns\ParsesDays;
use App\Services\Reminders\ReminderService;
use Illuminate\Console\Command;

class SendOverdueInvoiceReminders extends Command
{
    use ParsesBoundedInteger, ParsesDays;

    private const int MIN_DAYS = 1;

    private const int MAX_DAYS = 90;

    protected $signature = 'invoices:send-overdue-reminders
        {--every-days=7 : No vuelve a avisar de una factura hasta pasados estos días (1 a 90)}
        {--as-of= : Fecha de referencia del vencimiento AAAA-MM-DD; por defecto, hoy}
        {--dry-run : Dice cuántas avisaría sin marcar ni enviar nada}';

    protected $description = 'Avisa a los titulares de las facturas vencidas con saldo pendiente';

    public function handle(ReminderService $reminders): int
    {
        $everyDaysOption = $this->option('every-days');
        $everyDays = $this->parseBoundedInteger($everyDaysOption, self::MIN_DAYS, self::MAX_DAYS);

        if ($everyDays === null) {
            return $this->invalidInteger('every-days', $everyDaysOption, self::MIN_DAYS, self::MAX_DAYS);
        }

        $asOfOption = $this->option('as-of');
        $asOf = $asOfOption === null ? today() : $this->parseDay($this->asText($asOfOption));

        if ($asOf === null) {
            return $this->invalidDay($this->asText($asOfOption));
        }

        $now = now();

        if ($this->option('dry-run')) {
            $this->info(__('Simulación: facturas vencidas que se avisarían a fecha :date: :count. No se ha enviado nada.', [
                'date' => $asOf->format('d/m/Y'),
                'count' => $reminders->countOverdue($now, $asOf, $everyDays),
            ]));

            return self::SUCCESS;
        }

        $this->info(__('Avisos de factura vencida enviados: :count.', [
            'count' => $reminders->remindOverdue($now, $asOf, $everyDays),
        ]));

        return self::SUCCESS;
    }
}
