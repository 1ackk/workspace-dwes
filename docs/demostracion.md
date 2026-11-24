# WorkSpace · Guion de demostración del sprint 5

Eventos, trabajos en segundo plano y comunicaciones, de principio a fin. Todos los correos se escriben
en `storage/logs/laravel.log` (`MAIL_MAILER=log`) y los avisos de Telegram en
`storage/logs/notifications.log` (canal simulado): **no sale nada a Internet**.

Las salidas de este guion son las de una ejecución real sobre una base recién sembrada. Las fechas
dependen del día en que se haga: donde pone "mes anterior" es el mes anterior al de hoy (por ejemplo,
`2026-10` si hoy es noviembre de 2026).

## a) Preparación

1. En `.env` (partiendo de `.env.example`):

   ```dotenv
   APP_NAME=WorkSpace
   QUEUE_CONNECTION=database
   MAIL_MAILER=log
   NOTIFICATIONS_CHANNEL=simulated
   TELEGRAM_BOT_TOKEN=
   ```

   `APP_NAME` es el nombre que firma los correos y el aviso de Telegram ("WorkSpace: reserva
   confirmada."). Después de tocar `.env`: `php artisan config:clear`.

2. Base de datos y estilos:

   ```bash
   php artisan migrate:fresh --seed
   npm install
   npm run build
   ```

   `npm run build` genera `public/build`, que no está en Git: sin él, las páginas dan error 500.

3. Dos terminales abiertas durante toda la demostración:

   ```bash
   php artisan serve
   ```

   ```bash
   php artisan queue:work --queue=mail,default
   ```

   El worker atiende primero la cola `mail`, donde van todos los correos en cola y el aviso de
   Telegram. Si el código cambia, reinícialo (`php artisan queue:restart`).

**Para ver lo que llega**, en una tercera terminal (PowerShell) se puede seguir el final de cada log:

```powershell
Get-Content storage\logs\laravel.log -Tail 40 -Wait
```

```powershell
Get-Content storage\logs\notifications.log -Tail 10 -Wait
```

## b) Flujo 1: reservar y pagar desde el calendario (Livewire)

1. Entra en `http://127.0.0.1:8000` como `usuario1@workspace.test` / `password` y pulsa **Reservar**.
2. Elige **Planta Baja · Sala de reuniones** y deja el día de mañana. Se ven de 10:00 a 12:00 como
   **Ocupada** (la reserva multirrecurso del seeder) y el resto **Libre**.
3. Haz clic en **16:00** y después en **17:30**: "Tramo elegido: de 16:00 a 18:00" con su precio
   (incluye el recargo de tarde).
4. **Reservar**: aparece "Reserva retenida" con la hora de caducidad (15 minutos).
5. **Pagar (simulado)**: "Pago recibido: tu reserva está confirmada." con el enlace al detalle.
6. En la terminal del worker:

   ```
   App\Jobs\SendBookingConfirmedMailJob ...... DONE
   App\Jobs\SendBookingConfirmedTelegramJob ... DONE
   ```

7. En `laravel.log`, el correo `To: Miembro 1 <usuario1@workspace.test>` con
   `Subject: Reserva confirmada para el …`. En `notifications.log`:

   ```
   local.INFO: Mensaje simulado {"to":"*****0001","text":"WorkSpace: reserva confirmada.
   - Sala de reuniones (Planta Baja): …, 16:00–18:00
   Total: … €"}
   ```

   `usuario1` tiene Telegram activado en el seeder (identificador ficticio `100000001`, enmascarado en
   el log). Se ve y se cambia en **Notificaciones**.

## c) Flujo 2: cancelar con antelación suficiente

La web todavía no tiene botón de cancelar (ver "Límites conocidos" en el README), así que se hace con
Tinker, con el mismo servicio que usaría la pantalla. En `php artisan tinker`:

```php
$service = app(App\Services\Booking\BookingService::class);
$user = App\Models\User::where('email', 'usuario2@workspace.test')->first();
$sala = App\Models\Resource::whereHas('space', fn ($q) => $q->where('name', 'Planta Baja'))->where('name', 'Sala 2')->first();
$day = now()->addDays(7);
$booking = $service->confirm($service->createPending($user, [new App\Services\Pricing\Slot($sala, $day->copy()->setTime(10, 0), $day->copy()->setTime(11, 0))]));
$service->cancel($booking);
```

- El worker procesa `SendBookingConfirmedMailJob` y después `SendBookingCancelledMailJob`.
- En `laravel.log`: `To: Miembro 2 <usuario2@workspace.test>` y `Subject: Reserva cancelada del …`.
- `$service->cancel($booking)` otra vez lanza "La reserva ya está cancelada." y no envía nada.

## d) Flujo 3: caducidad de una reserva sin pagar

El seeder deja una reserva pendiente de `usuario1` con la retención ya vencida (Puesto 5).

```bash
php artisan bookings:release-unpaid --dry-run
php artisan bookings:release-unpaid
php artisan bookings:release-unpaid
```

```
Simulación: reservas que se liberarían: 1. No se ha cambiado nada.
Reservas liberadas: 1.
Reservas liberadas: 0.
```

- El correo `Tu reserva del … ha caducado` sale **al momento**, sin pasar por el worker: este job es
  síncrono.
- La segunda ejecución no cambia nada ni envía nada. Lo mismo desde el panel del administrador
  (`admin@workspace.test`, botón "Liberar reservas sin pagar").
- Para provocar otra caducidad: reserva en el calendario sin pagar y, en Tinker,
  `App\Models\Booking::latest('id')->first()->update(['expires_at' => now()->subMinute()]);` antes de
  volver a lanzar el comando.

**Atención:** si se ejecuta pasados 15 minutos del `migrate:fresh --seed`, dirá **2**: el seeder también
crea una reserva pendiente con la retención vigente (Sala 1), que para entonces ya habrá vencido.

## e) Flujo 4: recordatorio de reserva próxima

El seeder tiene dos reservas confirmadas para mañana (la multirrecurso de `usuario1` y la de
`usuario2`). Con una ventana de 48 horas entran siempre, se haga a la hora que se haga:

```bash
php artisan bookings:remind-upcoming --hours=48 --dry-run
php artisan bookings:remind-upcoming --hours=48
php artisan bookings:remind-upcoming --hours=48
```

```
Simulación: reservas que se recordarían en las próximas 48 horas: 2. No se ha enviado nada.
Recordatorios enviados: 2.
Recordatorios enviados: 0.
```

- Dos correos `Recordatorio: tu reserva empieza el … a las …`, al momento (job síncrono).
- La segunda ejecución envía 0: cada reserva queda marcada (`reminder_sent_at`) y no se repite.
- Si en el flujo 1 se reservó para mañana o pasado, esa reserva también entra y dirá 3.

## f) Flujo 5: facturas del mes anterior y avisos de vencimiento

```bash
php artisan invoices:generate 2026-10 --dry-run
php artisan invoices:generate 2026-10
php artisan invoices:generate 2026-10
```

```
| usuario1@workspace.test | 2 | 28.50 |
| usuario2@workspace.test | 1 | 50.00 |
| usuario4@workspace.test | 1 | 4.50  |
Simulación: se crearían 3 facturas. No se ha escrito nada.
Facturas generadas en 10/2026: 3. Reservas facturadas: 4. Importe total: 83.00 €.
No hay reservas que facturar en 10/2026.
```

(cambia `2026-10` por el mes anterior al de hoy). Las facturas nuevas son de la `FAC-2026-000007` a
la `000009`, y vencen el último día del mes facturado más 15 días (`INVOICE_DUE_DAYS`). Si hoy es
posterior a ese vencimiento, ya están vencidas:

```bash
php artisan invoices:list-overdue
php artisan invoices:send-overdue-reminders --dry-run
php artisan invoices:send-overdue-reminders
php artisan invoices:send-overdue-reminders
```

```
Simulación: facturas vencidas que se avisarían a fecha …: 6. No se ha enviado nada.
Avisos de factura vencida enviados: 6.
Avisos de factura vencida enviados: 0.
```

- Las 6 son las 3 nuevas y las vencidas del seeder (3, 4 y 5; la 6 está pagada del todo y no entra).
- Estos avisos **van a la cola**: el worker procesa 6 `SendInvoiceOverdueMailJob` y los correos
  `Factura FAC-… vencida: saldo pendiente` aparecen en `laravel.log`. El de la `000004` muestra la
  mitad del importe como saldo (tiene un pago parcial).
- La segunda ejecución envía 0: no se repite hasta pasados 7 días (`--every-days`).
- Lo mismo desde la web: `contable@workspace.test` → **Contabilidad** → "Generar facturas del mes".

## g) Jobs fallidos

```bash
php artisan queue:failed
```

Con el canal simulado y el correo en log no falla ninguno: dice `No failed jobs found.`. Si un job
agota sus intentos (o un aviso de Telegram recibe un error permanente), aparece ahí con su uuid, y
además deja una línea en `laravel.log` con el id de la reserva o la factura. Para reintentarlo:

```bash
php artisan queue:retry all
```

o `php artisan queue:retry <uuid>` para uno solo. `php artisan queue:flush` los borra.

## h) Pruebas

```bash
composer test
php artisan test --coverage
```

`composer test` pasa el estilo (Pint), los tipos (Larastan) y la suite de Pest en SQLite en memoria,
sin tocar la base de desarrollo. La concurrencia real (reservas, cancelaciones, confirmaciones y
facturación simultáneas) necesita MySQL y la base dedicada `workspace_test`; en PowerShell:

```powershell
$env:DB_CONNECTION='mysql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3306'; $env:DB_DATABASE='workspace_test'; $env:DB_USERNAME='root'; $env:DB_PASSWORD=''; php artisan test --group=concurrency; Remove-Item Env:DB_*
```

Detalles en el README ("Prueba de concurrencia (MySQL)") y en [reservas.md](reservas.md).
