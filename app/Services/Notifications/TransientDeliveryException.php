<?php

namespace App\Services\Notifications;

use RuntimeException;

/**
 * El mensaje no se ha entregado ahora pero puede entregarse más tarde: error del servidor (5xx),
 * timeout, fallo de conexión o demasiados envíos (429). El job lo reintenta con su backoff.
 *
 * Su mensaje nunca incluye el token ni la URL de la API.
 */
class TransientDeliveryException extends RuntimeException {}
