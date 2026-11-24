<?php

namespace App\Listeners;

use App\Events\BookingConfirmed;
use App\Jobs\SendBookingConfirmedTelegramJob;
use Throwable;

/**
 * A2: segundo listener de BookingConfirmed (el primero envía el correo). Solo delega, y solo si el
 * titular tiene Telegram activado. Es independiente del correo: si Telegram falla, el correo y la
 * confirmación no se ven afectados.
 */
class SendBookingConfirmedTelegram
{
    public function handle(BookingConfirmed $event): void
    {
        if ($event->booking->user?->telegram_chat_id === null) {
            return;
        }

        // Con la cola "database" esto solo encola el job. Con la cola "sync" el job se ejecuta aquí
        // mismo, y un fallo de Telegram no debe romper la confirmación: se registra y se sigue.
        try {
            SendBookingConfirmedTelegramJob::dispatch($event->booking);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
