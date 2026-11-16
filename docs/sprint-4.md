# WorkSpace · Cierre del sprint 4

## Qué se ha construido

| Bloque | Contenido | Documento |
|---|---|---|
| Policies | 5 en total: Booking, Resource, Incident, Invoice y Space | [permisos.md](permisos.md) |
| Scopes | 7, de ellos 4 complejos: `available`, `cancellable`, `overdueInvoices` y `occupancyByPeriod` | [scopes.md](scopes.md) |
| Servicio de reservas | Crear con bloqueo, calcular el precio, cancelar con plazo, liberar y completar | [reservas.md](reservas.md) |
| Comandos | 4 de 7, de ellos 2 de 3 llamados desde el código, con panel de mantenimiento | [comandos.md](comandos.md) |

## Guion de demostración

Los resultados de este guion se han comprobado ejecutándolo sobre una base recién sembrada. Las fechas
dependen del día en que se ejecute; lo demás no. Las cuentas de demostración son `usuario1@` … `5@`,
`responsable1@`/`2@`, `contable@`, `recepcion@` y `admin@`, todas en `workspace.test` y con la
contraseña `password`.

### Preparación

**Atención:** este comando borra y reconstruye la base de datos configurada en `.env`.

```bash
php artisan migrate:fresh --seed
```

Haz los pasos seguidos: la reserva pendiente de Sala 1 retiene 15 minutos desde el sembrado. Si pasan
más, la ocupación de Sala 1 baja a 0 h y el botón de liberar dirá 2 en lugar de 1.

Abre `php artisan tinker` y pega estas líneas:

```php
use App\Models\{Booking, Incident, Invoice, Resource, Space, User};
use App\Services\Booking\BookingService;
use App\Services\Pricing\Slot;
use Illuminate\Support\Carbon;

$service = app(BookingService::class);
$user = fn (string $name) => User::where('email', "{$name}@workspace.test")->firstOrFail();
$planta = Space::where('name', 'Planta Baja')->firstOrFail();
$sala = $planta->resources()->where('name', 'Sala 1')->firstOrFail();
$day = now()->addWeek()->next(Carbon::TUESDAY);
$slot = new Slot($sala, $day->copy()->setTime(10, 0), $day->copy()->setTime(12, 0));
```

Se usa un martes a más de una semana vista, a las 10:00, para que no influyan el recargo de tarde, el de
fin de semana ni el plazo de cancelación.

### 1. Reserva con precio calculado

```php
$reserva = $service->createPending($user('usuario1'), [$slot]);
[$reserva->status->value, $reserva->total];
```

**Esperado:** `["pending_payment", "24.00"]`: 2 h de Sala 1 a 12,00 €/h. Retiene la franja 15 minutos.

### 2. Intento de doble reserva

```php
try { $service->createPending($user('usuario2'), [$slot]); } catch (\App\Exceptions\SlotUnavailableException $e) { echo $e->getMessage(); }
```

**Esperado:** `Sala 1 no está disponible el <martes> de 10:00 a 12:00.`

### 3. Cancelación dentro y fuera de plazo

Dentro de plazo:

```php
$service->cancel($reserva)->status->value;
Resource::query()->whereKey($sala->id)->available($slot->start, $slot->end)->exists();
```

**Esperado:** `"cancelled"` y `true`: la franja vuelve a estar libre.

Repetir la cancelación:

```php
try { $service->cancel($reserva); } catch (\App\Exceptions\BookingNotCancellableException $e) { echo $e->getMessage(); }
```

**Esperado:** `La reserva ya está cancelada.`

Fuera de plazo, con la reserva confirmada del seeder. Se simula estar una hora antes de su inicio para
que el resultado no dependa de la hora real:

```php
$confirmada = Booking::whereBelongsTo($user('usuario1'))->where('status', 'confirmed')->firstOrFail();
Carbon::setTestNow($confirmada->slots->min('start_at')->copy()->subHour());
try { $service->cancel($confirmada); } catch (\App\Exceptions\BookingNotCancellableException $e) { echo $e->getMessage(); }
Carbon::setTestNow();
$confirmada->fresh()->status->value;
```

**Esperado:** `Solo se puede cancelar con 24 horas de antelación (hasta el <hoy> a las 10:00).` y
`"confirmed"`: no ha cambiado.

### 4. Un permitido y un denegado de cada policy

```php
$incidencia = Incident::query()->firstOrFail();
$factura = Invoice::whereBelongsTo($user('usuario1'))->firstOrFail();
$proyector = $planta->resources()->where('name', 'Proyector')->firstOrFail();
```

| Policy | Línea | Esperado |
|---|---|---|
| Booking | `$user('usuario1')->can('cancel', $confirmada)` | `true` |
| Booking | `$user('usuario2')->can('cancel', $confirmada)` | `false` |
| Resource | `$user('responsable1')->can('update', $proyector)` | `true` |
| Resource | `$user('responsable2')->can('update', $proyector)` | `false` |
| Incident | `$user('responsable1')->can('update', $incidencia)` | `true` |
| Incident | `$user('responsable2')->can('view', $incidencia)` | `false` |
| Incident | `$user('recepcion')->can('view', $incidencia)` | `true` |
| Incident | `$user('recepcion')->can('respond', $incidencia)` | `false` |
| Invoice | `$user('contable')->can('void', $factura)` | `true` |
| Invoice | `$user('usuario2')->can('view', $factura)` | `false` |
| Invoice | `$user('admin')->can('delete', $factura)` | `false` |
| Space | `$user('responsable1')->can('update', $planta)` | `true` |
| Space | `$user('responsable2')->can('update', $planta)` | `false` |
| Space | `Gate::forUser(null)->allows('view', $planta)` | `true` (visitante) |

### 5. Los dos scopes complejos nuevos

```php
Invoice::query()->overdueInvoices()->orderBy('id')->get()->map(fn ($i) => [$i->user->email, $i->amount, $i->balance]);
```

**Esperado:** tres facturas: `usuario5@` con saldo 9,00; `usuario3@` con 4,50 (pago parcial) y `usuario4@`
con 9,00 (pago fallido). No sale la factura de la Sala 2, pagada del todo.

```php
Resource::query()->forSpace($planta->id)->occupancyByPeriod(today()->subDays(20), today()->addDays(2))->get()->map(fn ($r) => [$r->name, $r->occupancy->bookedHours, $r->occupancy->availableHours, $r->occupancy->percentage()]);
```

**Esperado:** 264 h disponibles por recurso (22 días × 12 h). Por ejemplo: Puesto 1, 4 h y 1,52 %; Puesto 6,
2 h y 0,76 %; Sala 2, 3 h y 1,14 %. Puesto 5 da 0 h porque su reserva pendiente ya caducó.

Sal de tinker con `exit`.

### 6. Los dos informes por consola

```bash
php artisan invoices:list-overdue
```

**Esperado:** la tabla con las tres facturas anteriores y 5 días de retraso. Código de salida 0.

```bash
php artisan invoices:list-overdue --as-of=2026-02-31
```

**Esperado (error):** `Fecha no válida: "2026-02-31". Usa el formato AAAA-MM-DD.` Código de salida 1.

```powershell
php artisan occupancy:report (Get-Date).AddDays(-20).ToString('yyyy-MM-dd') (Get-Date).AddDays(1).ToString('yyyy-MM-dd') --space=1
```

**Esperado:** los 10 recursos de Planta Baja, con 264,00 h disponibles cada uno. Código de salida 0.

```bash
php artisan occupancy:report 2026-12-10 2026-12-01
```

**Esperado (error):** `La fecha final no puede ser anterior a la inicial.` Código de salida 1.

```bash
php artisan occupancy:report 2026-12-01 2026-12-10 --space=999
```

**Esperado (error):** `El espacio "999" no existe.` Código de salida 1.

### 7. Los dos comandos de mantenimiento, en simulación

```bash
php artisan bookings:release-unpaid --dry-run
```

**Esperado:** `Simulación: reservas que se liberarían: 1. No se ha cambiado nada.`

```bash
php artisan bookings:complete-past --dry-run
```

**Esperado:** `Simulación: reservas que se completarían: 1. No se ha cambiado nada.`

### 8. Panel de mantenimiento

Con la aplicación en marcha, entra como `admin@workspace.test` y abre `/admin`.

| Acción | Resultado esperado |
|---|---|
| Pulsa **Liberar reservas sin pagar** | `Reservas liberadas: 1.` |
| Pulsa **Completar reservas terminadas** | `Reservas completadas: 1.` |
| Pulsa otra vez cualquiera de los dos | El mismo mensaje con `0` |
| Entra como `usuario1@workspace.test` y abre `/admin` | Error 403 |

### 9. Idempotencia por consola

```bash
php artisan bookings:release-unpaid
```

**Esperado:** `Reservas liberadas: 0.`

```bash
php artisan bookings:complete-past
```

**Esperado:** `Reservas completadas: 0.`

## Decisiones de diseño para la defensa

**Reparto entre policy y servicio.** La policy responde a *quién* y no mira el estado ni la fecha. Lo que
depende de ellos lo decide el servicio o un scope. Por ejemplo, `BookingPolicy::cancel` dice quién puede
cancelar y el scope `cancellable`, llamado por `BookingService`, dice si todavía se puede. Así cada regla
está en un solo sitio.

**Panel de mantenimiento protegido solo por rol.** `POST admin/maintenance/{task}` usa el middleware del
rol administrador y no una policy, porque no actúa sobre un registro concreto. Los tests cubren los cinco
roles y al visitante. Además, el nombre del comando sale de un array fijo y nunca de la petición. Las
cuatro páginas provisionales de cada zona tampoco tienen policy: no muestran datos.

**Bloqueo y orden de ids.** Crear una reserva bloquea a la vez todas las filas de `resources`, en una sola
consulta y por id ascendente. Dos procesos que quieren los mismos recursos los piden en el mismo orden, así
que no puede formarse un ciclo de esperas. Por eso el reintento tras un interbloqueo no se prueba: está
configurado (3 intentos), pero el orden evita llegar a ese caso.

**El bloqueo es la primera consulta.** En `REPEATABLE READ`, la primera lectura normal fija la foto de
datos. Una lectura previa haría que, tras esperar el bloqueo, no se viera la reserva del otro proceso. Está
medido: con una sola lectura antes del bloqueo, 4 de 4 procesos reservaron la misma franja.

**Caducidad de las pendientes.** Una reserva `pending_payment` con `expires_at` vencido deja de ocupar la
franja aunque su estado no haya cambiado (`Booking::blocking`). El comando `release-unpaid` solo pone el
estado al día; la disponibilidad no depende de que haya corrido.

**UPDATE condicional.** Los comandos leen los ids y actualizan cada reserva repitiendo la regla completa
en el `UPDATE`. Si otra petición la confirmó entre medias, la fila ya no cumple la condición y no se pisa.

**Redondeo del saldo.** `overdueInvoices` calcula el saldo en SQL con `ROUND(..., 2)` porque SQLite guarda
los decimales en coma flotante y 0,10 + 0,20 − 0,30 dejaría un resto. Sin él, una factura pagada del todo
saldría como vencida. Los precios se calculan en céntimos enteros, sin coma flotante.

**Saldo calculado, no `amount`.** El saldo es reservas facturables menos pagos completados. Una factura
sin reservas no tiene saldo, aunque su `amount` sea mayor que 0.

**Propiedad `$occupancy`.** Recortar franjas no se puede escribir en SQL portable, así que el scope carga
solo lo necesario y calcula en PHP. El resultado queda en una propiedad **declarada** de `Resource`:
si no estuviera declarada, Eloquent la convertiría en atributo e intentaría guardarla. El periodo se indica
una sola vez, de modo que no puede desencajar con las franjas cargadas. Son 3 consultas, haya los recursos
que haya.

**Laravel 13 y CSRF.** El middleware `PreventRequestForgery` rechaza con 419 las peticiones sin token que
vienen de otro sitio, pero acepta las del mismo origen por la cabecera `Sec-Fetch-Site`. Los formularios
llevan `@csrf` igualmente.

## Limitaciones conocidas

- **Sin pantalla de reservas:** crear y cancelar existen como servicio, sin ruta ni vista; el calendario es
  del sprint 5. Hasta entonces se demuestran en tinker.
- **Sin confirmación de pago:** no hay código que pase una reserva a `confirmed` ni una factura a `paid`.
  Por eso la factura "pagada del todo pero aún `pending`" del seeder es solo un dato de prueba.
- **Policies aún sin uso:** Incident, Invoice y Space están probadas por `Gate` y `can`, pero ninguna ruta las
  llama todavía. Tampoco existe aún el código que valide las transiciones de estado de facturas e incidencias.
- **Concurrencia:** probadas la creación y dos cancelaciones simultáneas. No se ha probado una cancelación
  simultánea con una confirmación de pago, porque esta aún no existe, ni el reintento tras un interbloqueo.
- **`withoutOverlapping`:** está configurado y comprobado por test, pero no se han provocado dos ejecuciones
  solapadas reales.
- **Motores:** se ha ejecutado en SQLite y MySQL 8.4, no en MariaDB ni PostgreSQL.
- **Traducción:** los textos están en español con `__()`, sin carpeta `lang/`. La traducción es/en y la
  auditoría de FormRequest son del sprint 6.
- **Datos del seeder:** los precios de las reservas sembradas salen de `withSlot` (tarifa base × horas), no de
  `PriceCalculator`.
- **`$occupancy` y `cursor()`:** con `cursor()` no hay eager loading y `$occupancy` queda en `null`. Hay que usar
  `get()`, `first()` o `paginate()`.
