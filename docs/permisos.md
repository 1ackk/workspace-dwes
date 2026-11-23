# WorkSpace · Roles y permisos

Los roles y permisos se crean en `database/seeders/RoleSeeder.php` con laravel-permission. Los
permisos dicen qué acción puede hacer un rol; qué registros concretos le corresponden lo
deciden las cinco policies de `app/Policies`.

## Roles

- **usuario**: miembro del coworking; reserva, paga las suyas y abre incidencias. Es el rol que recibe quien se registra desde la web.
- **recepción**: atiende en el mostrador; consulta espacios, recursos y usuarios, y gestiona reservas e incidencias en nombre de los miembros.
- **responsable de espacio**: gestiona su espacio y sus recursos, y atiende sus reservas e incidencias.
- **contable**: gestiona facturas y consulta pagos e informes.
- **administrador**: tiene todos los permisos.

## Matriz

- `✓`: tiene el permiso.
- `*`: la policy lo limita a lo suyo (sus reservas, sus espacios, sus recursos…).
- `†`: decisión del proyecto que CLAUDE.md no recoge expresamente.

| Permiso | usuario | recepción | responsable de espacio | contable | administrador |
|---|:-:|:-:|:-:|:-:|:-:|
| `spaces.view-any` | | ✓† | ✓* | | ✓ |
| `spaces.create` | | | | | ✓ |
| `spaces.update` | | | ✓* | | ✓ |
| `spaces.delete` | | | | | ✓ |
| `resources.view-any` | | ✓† | ✓* | | ✓ |
| `resources.create` | | | ✓* | | ✓ |
| `resources.update` | | | ✓* | | ✓ |
| `resources.delete` | | | | | ✓ |
| `bookings.view-any` | | ✓† | ✓* | | ✓ |
| `bookings.view` | ✓* | ✓† | ✓* | ✓*† | ✓ |
| `bookings.create` | ✓ | ✓† | | | ✓ |
| `bookings.cancel` | ✓* | ✓† | | | ✓ |
| `incidents.view-any` | | ✓† | ✓* | | ✓ |
| `incidents.view` | ✓* | ✓† | ✓* | | ✓ |
| `incidents.create` | ✓ | ✓† | ✓† | | ✓ |
| `incidents.update` | | | ✓* | | ✓ |
| `incidents.respond` | ✓* | ✓*† | ✓* | | ✓ |
| `invoices.view-any` | | | | ✓ | ✓ |
| `invoices.view` | ✓* | | | ✓ | ✓ |
| `invoices.create` | | | | ✓† | ✓ |
| `invoices.void` | | | | ✓† | ✓ |
| `payments.view-any` | | | | ✓† | ✓ |
| `payments.view` | ✓*† | | | ✓† | ✓ |
| `payments.create` | ✓* | | | | ✓ |
| `tariffs.view-any` | ✓† | ✓† | ✓† | ✓† | ✓ |
| `tariffs.manage` | | | | | ✓ |
| `users.view-any` | | ✓† | | | ✓ |
| `users.manage` | | | | | ✓ |
| `reports.view` | | | ✓*† | ✓† | ✓ |

## Notas

- La lectura de espacios y recursos es pública y no pasa por permiso; `spaces.view-any` y `resources.view-any` quedan solo para las pantallas de gestión.
- Los precios (tarifas) son de lectura pública.
- `incidents.update` sirve para cambiar el estado de una incidencia; `incidents.respond`, solo para comentar. Recepción ve todas las incidencias, pero solo responde a las que abrió ella.
- `payments.create` permite al usuario pagar su propia reserva.
- `bookings.view` permite al contable ver el detalle de sus propias reservas en "Mis reservas".
- `bookings.cancel` y `BookingPolicy::cancel` deciden quién puede cancelar. Cuándo se puede lo decide `BookingService` con el scope `cancellable` (ver [reservas.md](reservas.md)).

## Las cinco policies

Se registran solas por su nombre (`App\Policies\{Modelo}Policy`) y comprueban primero el permiso y
después la relación con el registro. No hay `Gate::before`: el administrador pasa porque tiene todos
los permisos, y por eso un `delete` que devuelve siempre falso también le afecta.

- **Sí:** puede. **No:** no puede. **Suyas / suyos:** solo los registros con los que tiene relación.
- El visitante sin autenticar solo puede leer el catálogo de espacios (`SpacePolicy::viewAny` y `view`).

### BookingPolicy

| Habilidad | usuario | recepción | responsable de espacio | contable | administrador |
|---|---|---|---|---|---|
| `viewAny` | No | Sí | Sí | No | Sí |
| `view` | Las suyas | Todas | Las suyas y las de sus recursos | Las suyas | Todas |
| `create` | Sí | Sí | No | No | Sí |
| `cancel` | Las suyas | Todas | No | No | Todas |

#### Reservas con recursos de varios espacios

Una reserva puede incluir recursos de espacios con responsables distintos (la relación `Booking` ↔ `Resource` es n:n).
Así se resuelve hoy su visibilidad:

- **Quién puede verla.** `BookingPolicy::view` (`app/Policies/BookingPolicy.php:16`) exige el permiso `bookings.view` y
  después una de tres relaciones: acceso completo (administrador o recepción), ser el dueño o gestionar la reserva.
  `managesBooking` (línea 60) cuenta una reserva como gestionada si **al menos uno** de sus recursos pertenece a un
  espacio cuyo `manager_id` es el responsable (`whereHas('space', ...)`, línea 64). Lo prueba el test
  `a booking with one managed resource among several counts as managed` de `BookingPolicyTest`.
- **Qué datos ve.** La autorización es de la reserva entera, no de cada línea. La única pantalla de detalle,
  `my-bookings/{booking}` (`MyBookingController::show`, que llama a `Gate::authorize('view', $booking)` y carga
  `resources.space`), muestra todas las líneas sin filtrar por espacio
  (`resources/views/my-bookings/show.blade.php:27-35`). Cada responsable con al menos un recurso en la reserva ve lo
  mismo: la fecha, el estado, el total de la reserva y, de **todas** las líneas, el recurso, el espacio, el inicio, el
  fin y el `precio_final`, también las de espacios que no gestiona. La vista no muestra quién hizo la reserva.
- **Qué no ve.** No tiene listado de las reservas de sus recursos: "Mis reservas" (`MyBookingController::index`) parte de
  `$request->user()->bookings()` y solo enseña las propias, aunque el rol tiene `bookings.view-any`. Al detalle llega
  solo con la URL. Tampoco ve la factura de la reserva: `InvoicePolicy::view` no incluye al responsable.

**Consultar no implica modificar.** Cada habilidad se decide por separado, y `view` solo da paso a la pantalla de
detalle, que es de solo lectura:

- **Cancelar:** el responsable no tiene el permiso `bookings.cancel`, así que `BookingPolicy::cancel` le devuelve
  `false` aunque la reserva sea entera de su espacio, y `cancel` no mira `managesBooking`.
- **Crear o cambiar reservas:** no tiene `bookings.create`, y no existe ninguna habilidad ni ruta para editar una
  reserva.
- **Tocar los recursos de otro espacio:** ver una línea de otro espacio no le da derechos sobre ese recurso.
  `ResourcePolicy` compara el `manager_id` del espacio de cada recurso, así que solo edita los de sus espacios.

### ResourcePolicy

| Habilidad | usuario | recepción | responsable de espacio | contable | administrador |
|---|---|---|---|---|---|
| `viewAny` (gestión) | No | Sí | Sí | No | Sí |
| `view` (gestión) | No | Todos | Los de sus espacios | No | Todos |
| `create` | No | No | En sus espacios | No | En cualquiera |
| `update` | No | No | Los de sus espacios | No | Todos |
| `delete` | No | No | No | No | Todos |

### IncidentPolicy

| Habilidad | usuario | recepción | responsable de espacio | contable | administrador |
|---|---|---|---|---|---|
| `viewAny` | No | Sí | Sí | No | Sí |
| `view` | Las suyas | Todas | Las suyas y las de sus recursos | No | Todas |
| `create` | Sí | Sí | Sí | No | Sí |
| `respond` | Las suyas | Solo las que abrió ella | Las suyas y las de sus recursos | No | Todas |
| `update` (estado o cierre) | No | No | Las de sus recursos | No | Todas |
| `delete` | No | No | No | No | No |

El responsable del recurso se deriva de `spaces.manager_id`. Un responsable que abre una incidencia
en un espacio ajeno la ve y responde, pero no cambia su estado.

Dónde se aplica: el detalle `incidents/{incident}` (`IncidentController::show`) llama a
`Gate::authorize('view', $incident)`. El responsable de otro espacio, un miembro que no es el autor y el contable
reciben 403.

### InvoicePolicy

| Habilidad | usuario | recepción | responsable de espacio | contable | administrador |
|---|---|---|---|---|---|
| `viewAny` | No | No | No | Sí | Sí |
| `view` | Las suyas | No | No | Todas | Todas |
| `create` | No | No | No | Sí | Sí |
| `void` (anular) | No | No | No | Sí | Sí |
| `delete` | No | No | No | No | No |

Las facturas nunca se borran: se anulan y se rectifican con otra.

Dónde se aplica: el detalle `invoices/{invoice}` (`InvoiceController::show`) llama a `Gate::authorize('view', $invoice)`,
así que una factura ajena da 403. El saldo pendiente que muestra sale de `Invoice::balanceQuery()`, el mismo cálculo que
usa el scope `overdueInvoices`.

### SpacePolicy

| Habilidad | visitante | usuario | recepción | responsable de espacio | contable | administrador |
|---|---|---|---|---|---|---|
| `viewAny`, `view` (catálogo) | Sí | Sí | Sí | Sí | Sí | Sí |
| `viewManagement` (pantalla de gestión) | No | No | Sí | Sí | No | Sí |
| `create` | No | No | No | No | No | Sí |
| `update` | No | No | No | Los suyos | No | Todos |
| `delete` | No | No | No | No | No | Todos |

`update` cubre la gestión del espacio. Gestionar sus recursos lo decide `ResourcePolicy::create($space)`.

Dónde se aplica: el listado `manager/spaces` usa `can:viewManagement` y la edición (`manager/spaces/{space}/edit` y
su `PUT`) usa `can:update,space`, las dos como middleware en `routes/web.php`. El grupo de rutas solo deja entrar a
responsables y administradores, así que recepción, que tiene `spaces.view-any`, recibe un 403 en el listado.

## Qué decide la policy y qué decide el servicio

La policy responde a **quién** puede hacer algo y no mira el estado ni la fecha. Lo que depende de
ellos lo deciden los servicios o los scopes:

| Pregunta | Quién responde |
|---|---|
| ¿Quién puede cancelar una reserva? | `BookingPolicy::cancel` |
| ¿Sigue estando a tiempo de cancelarse? | `BookingService` con el scope `cancellable` |
| ¿Quién puede anular una factura? | `InvoicePolicy::void` |
| ¿Esa factura admite anularse (por ejemplo, ya está anulada)? | El servicio de facturación |
| ¿Quién puede cambiar el estado de una incidencia? | `IncidentPolicy::update` |
| ¿Ese cambio de estado es válido? | El código que haga el cambio |
