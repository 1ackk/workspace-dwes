<?php

namespace App\Services\Notifications;

use App\Contracts\MessageChannel;
use Illuminate\Support\Facades\Log;

/**
 * Canal por defecto y de las pruebas: no sale nada a Internet. Escribe cada mensaje en
 * storage/logs/notifications.log (canal de log "notifications"), con el destinatario enmascarado.
 */
class SimulatedChannel implements MessageChannel
{
    public function send(string $recipient, string $message): void
    {
        Log::channel('notifications')->info('Mensaje simulado', [
            'to' => self::mask($recipient),
            'text' => $message,
        ]);
    }

    /**
     * Deja ver solo los 4 últimos caracteres: "100000001" → "*****0001".
     */
    public static function mask(string $recipient): string
    {
        $visible = 4;

        if (strlen($recipient) <= $visible) {
            return str_repeat('*', strlen($recipient));
        }

        return str_repeat('*', strlen($recipient) - $visible).substr($recipient, -$visible);
    }
}
