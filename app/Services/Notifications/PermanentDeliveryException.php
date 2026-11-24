<?php

namespace App\Services\Notifications;

use RuntimeException;

/**
 * El mensaje no se podrá entregar aunque se reintente: chat inexistente, bot bloqueado por el
 * usuario, petición rechazada (4xx salvo 429). El job no lo reintenta.
 *
 * Su mensaje nunca incluye el token ni la URL de la API.
 */
class PermanentDeliveryException extends RuntimeException {}
