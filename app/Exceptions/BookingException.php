<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Base de los errores de dominio de las reservas. Su mensaje se puede mostrar al usuario.
 */
abstract class BookingException extends RuntimeException {}
