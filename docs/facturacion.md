# WorkSpace · Facturación: generar las facturas de un mes

Las facturas se generan por meses cerrados con el comando `invoices:generate`, desde consola o desde
el panel de contabilidad. La lógica está en `App\Services\Invoicing\InvoiceGenerator`; el comando y el
panel solo la llaman.

## Qué se factura

`php artisan invoices:generate 2026-11 [--user=ID] [--dry-run]`

- **Solo un mes ya terminado.** El último día del mes tiene que ser anterior a hoy, en la zona horaria
  de la aplicación (`Europe/Madrid`). Un mes en curso o futuro da error y código de salida 1. La regla
  es `App\Rules\FinishedMonth`, la misma para el comando y para el formulario del panel. El formato es
  `AAAA-MM` estricto: `2026-1`, `2026/11` o `2026-13` no valen.
- **Reservas facturables:** `status = completed`, no borradas lógicamente, sin factura
  (`invoice_id` nulo) y con `bookings.date` dentro del mes, primer y último día incluidos.
- **Una factura por usuario** con reservas facturables, recorriendo a los usuarios en orden de id. Los
  usuarios borrados lógicamente también se facturan: el histórico no se pierde.
- **`--user=ID`** factura solo a ese usuario (también si está borrado lógicamente). Un id que no existe
  da error y código 1.
- **`--dry-run`** muestra por usuario su email, el número de reservas y el importe, sin escribir nada.
- **Salida:** facturas generadas, reservas facturadas e importe total. Si no hay nada que facturar, lo
  dice y termina con código 0. Una segunda ejecución del mismo mes no crea nada y lo dice.
- **No lanza eventos ni correos.** El aviso de factura vencida es otro comando
  (`invoices:send-overdue-reminders`, ver [eventos.md](eventos.md)).

## La factura

| Campo | Valor |
|---|---|
| `number` | `FAC-{año de emisión}-{6 cifras}`, por ejemplo `FAC-2026-000007` |
| `status` | `pending` |
| `period_start` / `period_end` | Primer y último día del mes facturado |
| `amount` | Suma de los `total` de sus reservas |
| `issued_at` | El momento de la generación |
| `due_at` | `period_end` + `config('invoicing.due_days')` días (`INVOICE_DUE_DAYS`, 15 por defecto) |

Después, sus reservas reciben `invoice_id`. Una factura emitida no se borra ni se modifica: se anula y
se rectifica (ver [modelo-datos.md](modelo-datos.md)).

**Importes sin float.** Cada `total` llega con dos decimales (cast `decimal:2`) y se pasa a céntimos
enteros (`"12.50"` → `1250`). Se suma en enteros y se escribe otra vez con dos decimales. Así
`0.10 + 0.20` da exactamente `0.30`.

## El número de factura

Dentro de la transacción de cada usuario se toma el mayor sufijo de los números `FAC-{año}-…` (también
de las facturas borradas, que siguen ocupando su número) y se suma 1. El año es el de **emisión**: las
facturas de diciembre generadas en enero son `FAC-{año siguiente}-…`, como es habitual en facturación.

Por qué así, sin tabla de contadores: lo que garantiza que no haya dos facturas con el mismo número es
el índice único que `invoices.number` ya tiene. Si dos ejecuciones simultáneas calculan el mismo
número, el segundo `INSERT` espera al primero y falla con `UniqueConstraintViolationException`; la
transacción de ese usuario se repite entera y calcula el número siguiente. Se intenta hasta 3 veces;
si no se consigue, ese usuario se omite con un aviso y el lote sigue (sus reservas quedan sin factura
para la siguiente ejecución). Una tabla de secuencia con su fila bloqueada sería más estricta, pero
añadiría una migración y otro bloqueo para un caso que el índice único ya resuelve.

Las facturas de demostración del seeder tienen números fijos `FAC-{año}-000001` a `000006`, así que la
primera generada es la `000007`.

## Atomicidad e idempotencia

Cada factura se crea en **su propia transacción**:

1. La **primera consulta** bloquea (`lockForUpdate`) las reservas facturables del usuario, en orden
   ascendente de id. Es la primera a propósito: en MySQL con REPEATABLE READ, una lectura anterior al
   bloqueo fijaría la foto de datos y, tras esperar a otra ejecución, no veríamos que ya facturó esas
   reservas. Si ya no queda ninguna, se pasa al siguiente usuario.
2. Se suma el importe de las reservas **bloqueadas** y se crea la factura.
3. Se asigna `invoice_id` con un UPDATE condicional (`invoice_id` nulo y `status = completed`) y se
   comprueba que ha cambiado **exactamente** el número de reservas bloqueadas. Si no coincide, se lanza
   `BookingsChangedException` y la transacción entera se deshace: nunca queda una factura cuyo importe
   no corresponda con sus reservas. El usuario se omite con un aviso.

Dos ejecuciones simultáneas recorren a los usuarios en el mismo orden y bloquean las reservas en orden
de id: la segunda espera a la primera en cada usuario y, al conseguir el bloqueo, ya no encuentra
reservas sin factura. No se forma ningún ciclo de esperas, así que no hay interbloqueos.

Lo prueban:

- `tests/Feature/Invoicing/GenerateInvoicesTest.php`: reglas, opciones, número, reintentos y la
  condición atómica simulada con `DB::listen`.
- `tests/Concurrency/BookingConcurrencyTest.php` (MySQL): dos procesos facturan a la vez el mismo mes
  con tres usuarios, en 5 rondas. Cada reserva queda en una sola factura, hay una factura por usuario
  con su importe exacto y números distintos, y el contador `lock_deadlocks` no sube.

## El panel de contabilidad

`GET accounting` (`accounting.index`) y `POST accounting/invoices/generate`
(`accounting.invoices.generate`), dentro del grupo con `role:contable`.

- **Listado:** `Gate::authorize('viewAny', Invoice::class)` y las últimas facturas, 15 por página, con
  número, usuario (también si está dado de baja), periodo, importe, estado y vencimiento. El usuario se
  carga con *eager loading* (`withTrashed`): no hay consultas N+1.
- **Generar:** `GenerateInvoicesRequest` valida el mes (obligatorio y `FinishedMonth`, mensajes en
  español) y su `authorize()` usa `InvoicePolicy::create`. El controlador ejecuta
  `Artisan::call('invoices:generate', ['month' => …])` con el **nombre del comando fijo** y el mes ya
  validado como único argumento: desde el formulario no se puede pasar `--user` ni `--dry-run`. La
  salida del comando se muestra como mensaje flash, escapado; si el código de salida no es 0, en rojo.

Es el tercer comando llamado desde el código (los otros dos están en el panel de mantenimiento).
