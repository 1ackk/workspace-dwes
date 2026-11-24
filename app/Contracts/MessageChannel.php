<?php

namespace App\Contracts;

use App\Services\Notifications\PermanentDeliveryException;
use App\Services\Notifications\TransientDeliveryException;

/**
 * Canal de mensajes cortos al usuario, aparte del correo (A2). Hay dos implementaciones:
 * SimulatedChannel (por defecto y en las pruebas) y TelegramChannel. Cuál se usa lo decide
 * NOTIFICATIONS_CHANNEL (ver AppServiceProvider y docs/telegram.md).
 */
interface MessageChannel
{
    /**
     * Envía $message, en texto plano, al destinatario $recipient (en Telegram, el id de chat).
     *
     * @throws PermanentDeliveryException Si reintentar no servirá (chat inexistente, bot bloqueado).
     * @throws TransientDeliveryException Si puede funcionar más tarde (caída, timeout, límite de envíos).
     */
    public function send(string $recipient, string $message): void;
}
