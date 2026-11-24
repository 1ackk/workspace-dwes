<?php

namespace App\Services\Notifications;

use App\Contracts\MessageChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Envía el mensaje con la API de bots de Telegram (método sendMessage).
 *
 * - Texto plano: sin parse_mode, para que el nombre de un recurso no pueda meter formato.
 * - El token va en la URL de la API, así que nunca se copia el mensaje de error original (Guzzle
 *   incluye la URL en los errores de conexión): las excepciones llevan un texto propio.
 */
class TelegramChannel implements MessageChannel
{
    public const string API = 'https://api.telegram.org';

    public function __construct(
        private string $token,
        private int $timeout = 5,
    ) {
        if ($token === '') {
            throw new InvalidArgumentException(__('Falta TELEGRAM_BOT_TOKEN: el canal de Telegram necesita el token del bot.'));
        }
    }

    public function send(string $recipient, string $message): void
    {
        try {
            $response = Http::timeout($this->timeout)
                ->asJson()
                ->post(self::API.'/bot'.$this->token.'/sendMessage', [
                    'chat_id' => $recipient,
                    'text' => $message,
                ]);
        } catch (ConnectionException) {
            throw new TransientDeliveryException(__('No se pudo conectar con Telegram (timeout o red).'));
        }

        if ($response->successful()) {
            return;
        }

        // Telegram devuelve en "description" el motivo; no contiene el token.
        $reason = $response->json('description');
        $reason = is_string($reason) ? $reason : '';

        if ($response->status() === 429 || $response->serverError()) {
            throw new TransientDeliveryException(__('Telegram no aceptó el mensaje ahora (HTTP :status). :reason', [
                'status' => $response->status(),
                'reason' => $reason,
            ]));
        }

        throw new PermanentDeliveryException(__('Telegram rechazó el mensaje (HTTP :status). :reason', [
            'status' => $response->status(),
            'reason' => $reason,
        ]));
    }
}
