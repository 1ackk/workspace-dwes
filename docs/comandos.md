# WorkSpace · Comandos Artisan

**Contador del proyecto:** 4 de 7 comandos, 2 de 3 llamados desde el código. Los 3 restantes son del
sprint 5.

| Firma | Qué hace | Quién lo llama o cuándo | Códigos de salida | Ejemplo |
|---|---|---|---|---|
| `bookings:release-unpaid [--dry-run]` | Pasa a `expired` las reservas `pending_payment` con `expires_at` ya vencido | Botón del panel de administración (`Artisan::call`) y programador, cada minuto | 0 | `php artisan bookings:release-unpaid --dry-run` |
| `bookings:complete-past [--dry-run]` | Pasa a `completed` las reservas `confirmed` con franjas, todas ya terminadas | Botón del panel de administración (`Artisan::call`) y programador, cada hora | 0 | `php artisan bookings:complete-past` |
| `invoices:list-overdue [--as-of=AAAA-MM-DD]` | Lista las facturas vencidas: número, email, vencimiento, importe, saldo y días de retraso | A mano, en consola | 0 (también sin resultados); 1 si la fecha está mal formada | `php artisan invoices:list-overdue --as-of=2026-12-01` |
| `occupancy:report {from} {to} {--space=}` | Por recurso: horas reservadas, horas disponibles y porcentaje de ocupación | A mano, en consola | 0 (también sin recursos); 1 si una fecha no es válida, `to` es anterior a `from` o el espacio no existe | `php artisan occupancy:report 2026-11-01 2026-11-30 --space=1` |

## Los dos que modifican datos

La lógica está en `BookingService` y el comando solo la llama y escribe el resultado.

- **Son idempotentes:** una segunda ejecución no cambia nada y dice 0.
- **`--dry-run`** dice cuántas reservas cambiaría sin tocar ninguna.
- **No pisan cambios ajenos:** primero se leen los ids de las candidatas y luego cada reserva se
  actualiza con un `UPDATE` que repite la regla completa. Si entre medias se confirmó el pago, la fila
  ya no cumple la condición: no se pisa ni se cuenta.

Reglas de los límites:

- `release-unpaid` exige `expires_at < ahora`. Una retención que vence justo ahora se libera en la
  ejecución siguiente; durante ese instante ya no ocupa la franja.
- `complete-past` completa cuando ninguna franja tiene `end_at` posterior a ahora, así que una franja
  que termina justo ahora cuenta como terminada.
- Las reservas borradas lógicamente no se tocan.

## Qué comandos lanzan eventos

| Comando | Evento | Cuándo |
|---|---|---|
| `bookings:release-unpaid` | `BookingExpired`, uno por reserva | Solo por las reservas que su UPDATE cambió de verdad. Con `--dry-run`, ninguno. Una segunda ejecución no cambia nada y no lanza nada. El correo se envía de forma síncrona dentro del comando, y un fallo al enviarlo se registra sin cortar el resto. Desde el panel pasa lo mismo, porque el botón ejecuta este comando. |
| `bookings:complete-past` | Ninguno | No hay correo de "reserva completada" en el plan del sprint. |
| `invoices:list-overdue` | Ninguno | Es un informe de solo lectura. El aviso de factura vencida será otro comando, `invoices:notify-overdue`. |
| `occupancy:report` | Ninguno | Es un informe de solo lectura. |

La tabla completa de eventos, jobs y correos está en [eventos.md](eventos.md).

## Panel de mantenimiento

La vista de administración tiene dos botones, cada uno un formulario `POST` con `@csrf` a
`admin/maintenance/{task}` (ruta `admin.maintenance.run`). Solo entra el administrador: un visitante
va al login y otro rol recibe un 403.

El nombre del comando nunca viene del usuario. `MaintenanceController::TASKS` es un array fijo de
tarea a comando, la ruta solo acepta esas tareas y cualquier otro valor da un 404.

## Los dos informes

- Son de solo lectura y no se programan.
- En `occupancy:report` **ambos días se incluyen**: el comando llama al scope con `[from, to + 1 día)`.
- Los recursos inactivos también salen en el informe, porque tienen histórico.
- `invoices:list-overdue` sigue mostrando el email de un usuario borrado lógicamente: la factura
  sobrevive a su usuario.

## Cómo arrancar el programador

Las tareas programadas están en `routes/console.php`, con `withoutOverlapping`, y solo se ejecutan si
el programador está en marcha:

- **En desarrollo:** deja abierta una terminal con el comando siguiente.

  ```bash
  php artisan schedule:work
  ```

- **En un servidor:** una sola entrada de cron, cada minuto.

  ```bash
  * * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
  ```

`php artisan schedule:list` muestra lo programado y cuándo toca cada tarea.

## Demostración con el seeder

`CoworkingSeeder` deja, sin factura asociada, una reserva `pending_payment` ya vencida (Puesto 5) y
una `confirmed` ya terminada (Puesto 6). Después de `migrate:fresh --seed`, cada botón dice 1.

**Atención:** el seeder también crea una reserva pendiente vigente (Sala 1) con una retención de 15
minutos. Si pasan más de 15 minutos antes de pulsar el botón, `release-unpaid` dirá **2**.
