# WorkSpace · Eventos, listeners, jobs, colas y correos

Plan del sprint 5 para los mínimos M7 (5 eventos con 5 listeners), M8 (5 jobs, 3 en cola) y M9
(5 correos). Cada evento tiene un listener que no hace el trabajo: despacha un job, y el job envía el
correo.

## Tabla del sprint

| Estado | Evento | Lo lanza | Listener | Job | ¿Cola? | Correo |
|---|---|---|---|---|---|---|
| **Hecho** | `BookingConfirmed` | `BookingService::confirm()`, desde la web (pago simulado) | `SendBookingConfirmedMail` | `SendBookingConfirmedMailJob` | Sí, `mail` | `BookingConfirmedMail` |
| Pendiente | `BookingCancelled` | `BookingService::cancel()`, desde la web | `SendBookingCancelledMail` | `SendBookingCancelledMailJob` | Sí, `mail` | `BookingCancelledMail` |
| Pendiente | `InvoiceOverdue` | Comando nuevo `invoices:notify-overdue` (diario) sobre el scope `overdueInvoices` | `SendInvoiceOverdueMail` | `SendInvoiceOverdueMailJob` | Sí, `mail` | `InvoiceOverdueMail` |
| Pendiente | `BookingExpired` | `BookingService::releaseUnpaid()`, desde `bookings:release-unpaid` (cada minuto) | `SendBookingExpiredMail` | `SendBookingExpiredMailJob` | No | `BookingExpiredMail` |
| Pendiente | `BookingStartingSoon` | Comando nuevo `bookings:send-reminders` (cada hora) | `SendBookingReminderMail` | `SendBookingReminderMailJob` | No | `BookingReminderMail` |

Por qué cada uno va o no a la cola:

- **Confirmada y cancelada (en cola).** Nacen de una petición web con un usuario esperando: un servidor
  de correo lento o caído no debe retrasar ni romper la respuesta, y la cola reintenta sola.
- **Factura vencida (en cola).** Es un envío masivo de un golpe: cada correo se reintenta por separado
  y un fallo no corta el resto.
- **Caducada (síncrona).** La lanza el programador, sin nadie esperando, y son pocas por minuto (una
  por retención abandonada). El comando, protegido por `withoutOverlapping`, puede decir en su salida
  cuántos avisos ha enviado.
- **Recordatorio (síncrono).** El comando necesita saber que el correo salió para anotarlo
  (`reminded_at`, en una migración nueva) y no repetirlo a la hora siguiente. Además, en cola, con el
  worker parado llegaría tarde y dejaría de tener sentido.

Con los dos comandos nuevos el proyecto tendrá 6 de los 7 comandos de M6; falta uno más en el sprint.

## El flujo de una reserva confirmada

1. El dueño pulsa **Pagar (simulado)** en el detalle de su reserva pendiente
   (`POST my-bookings/{booking}/confirm`, protegido con `can:confirm,booking`).
2. `BookingService::confirm()` la pasa a `confirmed` con un UPDATE condicional y lanza
   `BookingConfirmed`. **`confirm` es el punto de enganche de una pasarela real (A3):** cuando el cobro
   se confirme, la pasarela llamará a este mismo método.
3. `BookingConfirmed` implementa `ShouldDispatchAfterCommit`: dentro de una transacción espera al commit
   y, si hay rollback, se descarta.
4. El listener `SendBookingConfirmedMail` (síncrono, descubierto solo por el tipo de su `handle()`)
   despacha `SendBookingConfirmedMailJob`.
5. El job va a la cola `mail` con `afterCommit()` (segunda garantía), `$tries = 3`,
   `$backoff = [10, 60]` y `$deleteWhenMissingModels`. Al ejecutarse vuelve a leer la reserva: si ya no
   está confirmada (se canceló entre medias) no envía nada. Si agota los intentos, `failed()` lo
   anota en el log y Laravel lo guarda en `failed_jobs`.
6. El worker procesa el job y envía `BookingConfirmedMail` (Markdown, en español): nombre del usuario,
   fecha, recursos con su espacio y franja, precio de cada línea y total. Sin correo, teléfono ni
   datos de pago.

### Por qué "después del commit"

Si el job llegara a la cola antes del commit, un worker rápido podría leer la reserva todavía
pendiente (o una confirmación que al final se revierte) y el correo diría algo falso. Por eso el evento
espera al commit y el job también. Lo prueban `BookingConfirmationTest` ("after commit") y el test de
concurrencia de dos confirmaciones simultáneas, que comprueba que se encola un solo job.

## Configuración

| Variable | `.env.example` | Tests (`phpunit.xml`) |
|---|---|---|
| `QUEUE_CONNECTION` | `database` (tabla `jobs`, ya migrada) | `sync`: el job se ejecuta en el momento |
| `MAIL_MAILER` | `log`: el correo se escribe en el log | `array`: se guarda en memoria |

El test de flujo completo cambia la cola a `database` solo para él y procesa el job con
`queue:work --stop-when-empty`.

## Arrancar el worker y el programador

En desarrollo, una terminal para cada uno:

```bash
php artisan queue:work --queue=mail,default
```

```bash
php artisan schedule:work
```

`--queue=mail,default` atiende primero la cola `mail` y después la `default`. Tras cambiar el código
de un job hay que reiniciar el worker (`php artisan queue:restart`), porque mantiene el código cargado.
Los jobs fallidos se ven con `php artisan queue:failed` y se reintentan con `php artisan queue:retry all`.

## Dónde ver los correos

Con `MAIL_MAILER=log`, cada correo se escribe completo (cabeceras y HTML) al final de
`storage/logs/laravel.log`. No sale ningún correo real.
