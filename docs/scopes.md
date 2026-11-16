# WorkSpace · Scopes

El proyecto tiene **7 scopes locales**, 4 de ellos complejos. Todos usan el atributo `#[Scope]`.
Funcionan igual en SQLite y en MySQL: la aritmética de fechas se hace en PHP y la consulta recibe
los valores ya calculados.

| Scope | Modelo | Qué devuelve | Tipo | Regla clave |
|---|---|---|---|---|
| `active` | Resource | Recursos activos | Simple | `active = true`; un recurso retirado conserva su histórico |
| `forSpace($spaceId)` | Resource | Recursos de un espacio | Simple | Filtra por `space_id` |
| `upcoming` | Booking | Reservas de hoy en adelante | Simple | Compara `date` con el día actual |
| `available($start, $end)` | Resource | Recursos libres en una franja | Complejo | Sin reserva bloqueante tal que `inicio_A < fin_B` y `fin_A > inicio_B` |
| `cancellable` | Booking | Reservas que aún se pueden cancelar | Complejo | Todas sus franjas cumplen el plazo de su espacio; manda el más estricto |
| `overdueInvoices($asOf)` | Invoice | Facturas vencidas con saldo por cobrar | Complejo | Reservas facturables − pagos completados > 0 |
| `occupancyByPeriod($from, $to)` | Resource | Recursos con su ocupación en un periodo | Complejo | Franjas recortadas al periodo sobre el horario real del espacio |

`Booking::blocking()` no es un scope. Es la regla común que dice qué reservas ocupan su franja, y la
usan `available`, `cancellable` y los dos scopes que siguen.

## overdueInvoices

Una factura está vencida si está `pending`, no está borrada, `due_at` es **anterior** a la fecha de
referencia (la que vence hoy aún no lo está) y su saldo es mayor que 0.

- **Saldo** = suma de `total` de sus reservas facturables − suma de `amount` de sus pagos `completed`.
- **Facturables:** las mismas que `Booking::blocking()`: `confirmed`, `completed` y `pending_payment`
  sin caducar. Una pendiente con la retención vencida no suma, aunque el comando de limpieza no
  haya corrido. Las borradas lógicamente tampoco.
- `$asOf` solo mueve el corte de vencimiento y se interpreta en la zona horaria de la aplicación. El
  saldo es siempre el actual.
- Es **una sola consulta**: una subconsulta suma las reservas y, dentro, otra suma los pagos. No se
  usa un `JOIN` de reservas y pagos porque multiplicaría las filas y sumaría de más.
- El saldo lleva `ROUND(..., 2)` porque SQLite guarda los decimales en coma flotante: 0,10 + 0,20 −
  0,30 no da exactamente 0.

El scope añade la columna calculada `balance`:

```php
Invoice::query()->overdueInvoices()->get()->pluck('balance', 'number');
Invoice::query()->overdueInvoices(now()->addMonth())->count(); // ¿Cuáles lo estarán dentro de un mes?
```

Una factura de 100 € con un pago completado de 40 € y otro fallido de 60 € tiene `balance = 60.00`.

## occupancyByPeriod

Calcula, para cada recurso, las horas reservadas y las disponibles en el periodo semiabierto
`[from, to)`. Si `to <= from` lanza `InvalidArgumentException`.

- **Reservadas:** solo cuentan las reservas que bloquean. Cada franja se recorta al periodo.
- **Disponibles:** por cada día natural que toca el periodo, la intersección del horario del espacio
  con `[from, to)`. Con días completos equivale a horario × días; con periodos parciales da la
  disponibilidad real.
- Un periodo fuera del horario tiene 0 horas disponibles y 0 %.

Recortar franjas no se puede escribir en SQL portable (`LEAST`, `GREATEST` y `TIMESTAMPDIFF` no
existen en todos los motores), así que el scope reparte el trabajo:

1. Hace eager loading del espacio y de **solo** las franjas bloqueantes que solapan con el periodo.
   Son siempre 3 consultas, haya los recursos que haya: no hay problema N+1.
2. Con `afterQuery`, deja en la propiedad `$occupancy` de cada recurso un objeto `ResourceOccupancy`
   con el cálculo en PHP. Así el periodo se indica una sola vez y no puede desencajar con las franjas
   cargadas.

```php
$resources = Resource::query()->occupancyByPeriod(today()->subDays(20), today()->addDays(2))->get();

$resources->first()->occupancy->bookedHours;    // horas reservadas
$resources->first()->occupancy->availableHours; // horas disponibles
$resources->first()->occupancy->percentage();   // porcentaje, redondeado a 2 decimales
```

Con horario de 08:00 a 20:00, el periodo de 10:00 a 12:00 de un día tiene 2 horas disponibles; una
reserva de 09:00 a 11:00 cuenta 1 hora y da un 50 %.

`$occupancy` es una propiedad declarada en `Resource`, no un atributo: no se guarda ni sale en
`toArray()`. El resultado se calcula en `get()`, `first()` y `paginate()`. `cursor()` no hace eager
loading, de modo que ahí `$occupancy` se queda en `null`. El scope devuelve también los recursos
inactivos; para excluirlos se encadena `->active()`.

## Datos de demostración

`CoworkingSeeder` deja seis facturas, con fechas relativas a hoy:

| Factura | Situación | ¿Vencida? | Saldo |
|---|---|---|---|
| Multirrecurso de mañana | Pendiente, vence dentro de 15 días | No | |
| Del Puesto 1 | Pagada | No | |
| Del Puesto 2 | Vencida sin pagos | **Sí** | 9,00 € |
| Del Puesto 3 | Vencida con un pago parcial | **Sí** | 4,50 € |
| Del Puesto 4 | Vencida con un pago fallido | **Sí** | 9,00 € |
| De la Sala 2 | Vencida, pagada del todo pero aún `pending` | No | 0,00 € |

La factura "pagada del todo pero aún `pending`" es un **dato de prueba para el caso límite**, no un
estado normal: al completarse el último pago, la factura debe pasar a `paid`. Está ahí para demostrar
que el scope decide por el saldo real y no por la etiqueta de estado.
