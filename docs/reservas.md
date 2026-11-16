# WorkSpace · Reservas: creación, retención y cancelación

Todo lo que cambia el estado de una reserva pasa por `App\Services\Booking\BookingService`. Los
precios los calcula `App\Services\Pricing\PriceCalculator` y la disponibilidad la decide el scope
`Resource::available`. El modelo de datos está en [modelo-datos.md](modelo-datos.md).

## Crear una reserva

`createPending(User $user, array $slots): Booking` recibe una lista de `Slot` (recurso, inicio y
fin) y crea una reserva `pending_payment` con todas las franjas, o no guarda nada.

1. **Antes de la transacción, sin consultas:** lista no vacía, fin posterior al inicio, mismo día,
   inicio y fin en bloques de 30 minutos, inicio posterior a ahora y franjas de un mismo recurso
   sin solapes entre sí. Las franjas pegadas y las disjuntas se permiten.
2. **Primera sentencia de la transacción:** bloquear las filas de `resources` implicadas con
   `lockForUpdate()`, en una sola consulta y ordenadas por id ascendente.
3. **Con el bloqueo tomado:** comprobar que cada recurso está activo y que la franja cabe en el
   horario `[opens_at, closes_at]` de su espacio, usando las filas recién bloqueadas.
4. Comprobar cada franja con `Resource::available`, que es el único sitio con la regla de solape.
5. Calcular los precios de todas las franjas de una vez.
6. Insertar la reserva y sus filas de la pivote con `precio_final`. `date` es el día de la franja
   más temprana y `total`, la suma de las franjas.

La transacción admite 3 intentos: Laravel solo repite los errores de concurrencia, como un
interbloqueo. Las excepciones de dominio salen a la primera.

### Por qué se bloquea en orden

Si un proceso bloquea A y luego B mientras otro bloquea B y luego A, cada uno espera al otro. Con
una sola consulta ordenada por id, todos los procesos toman los bloqueos en el mismo orden.

### Por qué se valida después de bloquear

MySQL usa por defecto `REPEATABLE READ`: la **primera lectura normal** de una transacción fija la
foto de datos que verán las siguientes. Un `SELECT ... FOR UPDATE` no la fija. Por eso el bloqueo
debe ser la primera consulta:

- **Bien:** el proceso espera el bloqueo y lee después, de modo que su foto ya incluye la reserva
  que acaba de confirmar el otro.
- **Mal:** si antes del bloqueo se hace cualquier `SELECT` (por ejemplo, para mirar si el recurso
  está activo), la foto es anterior. El proceso espera, obtiene el bloqueo y `available` sigue sin
  ver la otra reserva, así que se produce una doble reserva.

Es un fallo real y medido: al añadir una sola lectura antes del bloqueo, la prueba de concurrencia
obtuvo 4 reservas de 4 peticiones simultáneas sobre la misma franja.

Validar con las filas bloqueadas tiene otra ventaja: un recurso desactivado justo antes no se cuela.

## Retención de 15 minutos

Una reserva `pending_payment` guarda en `expires_at` el momento en que caduca. Son 15 minutos, por
`config('booking.hold_minutes')` (`BOOKING_HOLD_MINUTES`).

Una franja la ocupan las reservas `pending_payment`, `confirmed` y `completed`
(`BookingStatus::blocking()`). Una pendiente cuyo `expires_at` ya pasó **no ocupa nada**, aunque su
estado siga siendo `pending_payment`: la regla está en `Booking::blocking()` y no depende de que el
comando de limpieza haya corrido. Los comandos que cambian estos estados están en
[comandos.md](comandos.md).

## Cancelar una reserva

`cancel(Booking $booking): Booking` bloquea la fila de la reserva, pregunta al scope
`Booking::cancellable` y, si puede, la deja en `cancelled` con `cancelled_at`. La franja queda libre.

Una reserva es cancelable si cumple todo esto:

- Está en `pending_payment` (sin caducar) o `confirmed`. Una pendiente sigue la misma regla de
  plazo que una confirmada.
- **Todas** sus franjas cumplen `inicio − plazo del espacio ≥ ahora`
  (`spaces.cancellation_notice_hours`). La reserva se cancela entera, así que con varios espacios
  manda el plazo más estricto. Justo en el límite todavía se puede.

El reparto de responsabilidades evita tener dos reglas:

| Pregunta | Quién responde |
|---|---|
| ¿**Quién** puede cancelar? | `BookingPolicy::cancel`: permiso `bookings.cancel`, y ser dueño, recepción o administrador |
| ¿**Cuándo** se puede? | El scope `Booking::cancellable`, llamado por el servicio |

Si el scope dice que no, el servicio solo averigua el motivo para explicarlo. No vuelve a decidir.

## Excepciones

Todas heredan de `App\Exceptions\BookingException` y su mensaje se puede mostrar al usuario.

| Excepción | Cuándo |
|---|---|
| `App\Services\Pricing\InvalidSlotException` | Franja mal formada: fin no posterior al inicio, cruza la medianoche, fuera de la rejilla, en el pasado o fuera de horario |
| `InvalidBookingRequestException` | Lista vacía, franjas solapadas entre sí o recurso inactivo |
| `SlotUnavailableException` | La franja ya está ocupada; guarda la franja en `$slot` |
| `BookingNotCancellableException` | Ya cancelada, caducada, completada o fuera de plazo |

## Prueba de concurrencia

`tests/Concurrency/BookingConcurrencyTest.php` lanza procesos PHP reales contra MySQL, porque
`lockForUpdate()` no actúa en SQLite. Con SQLite se omite, así que `composer test` no la ejecuta.

Necesita una base **dedicada y local** llamada `workspace_test`, y se niega a ejecutarse contra
cualquier otra: hace `migrate:fresh` en ella. Las variables de entorno tienen prioridad sobre
`.env` y `phpunit.xml`, de modo que ni tu `.env` ni la base de desarrollo se tocan. Desde `proyecto/`,
en PowerShell y con la conexión `mysql`:

```powershell
$env:DB_CONNECTION='mysql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3306'; $env:DB_DATABASE='workspace_test'; $env:DB_USERNAME='root'; $env:DB_PASSWORD=''; php artisan test --group=concurrency; Remove-Item Env:DB_*
```

Cómo consigue la simultaneidad, sin `pcntl`: cada hijo (`tests/Concurrency/booking_worker.php`)
arranca Laravel y avisa `READY` por stdout. Cuando todos están listos, el padre les envía por stdin
una hora de salida común, 300 ms después. La prueba falla si algún hijo llega tarde.

Escenarios:

- 2 y 4 procesos piden la misma franja, en 5 rondas: se crea exactamente una reserva y el resto
  recibe `SlotUnavailableException`.
- Dos reservas con los recursos en orden opuesto (`[A,B]` y `[B,A]`): una gana y el contador
  `lock_deadlocks` de InnoDB no sube.
- Dos cancelaciones simultáneas de la misma reserva, en 5 rondas: una gana y la otra recibe "La
  reserva ya está cancelada."
- **Control negativo sin bloqueo:** comprobar e insertar sin bloquear sí produce doble reserva.
- **Control negativo de orden:** bloquear en orden opuesto sí hace subir el contador.

El reintento tras un interbloqueo (los 3 intentos de la transacción) no se prueba, porque el servicio
no llega a provocarlo: al bloquear siempre en orden ascendente de id no se forma el ciclo de esperas,
y el contador de interbloqueos no sube en el escenario de orden opuesto.

Los controles negativos usan modos que existen solo en el script de prueba y demuestran que la
prueba es capaz de detectar esos fallos. Si `lock_deadlocks` está desactivado, la prueba falla e
indica cómo activarlo: `SET GLOBAL innodb_monitor_enable = 'lock_deadlocks';`.
