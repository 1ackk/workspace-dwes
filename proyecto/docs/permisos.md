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

### InvoicePolicy

| Habilidad | usuario | recepción | responsable de espacio | contable | administrador |
|---|---|---|---|---|---|
| `viewAny` | No | No | No | Sí | Sí |
| `view` | Las suyas | No | No | Todas | Todas |
| `create` | No | No | No | Sí | Sí |
| `void` (anular) | No | No | No | Sí | Sí |
| `delete` | No | No | No | No | No |

Las facturas nunca se borran: se anulan y se rectifican con otra.

### SpacePolicy

| Habilidad | visitante | usuario | recepción | responsable de espacio | contable | administrador |
|---|---|---|---|---|---|---|
| `viewAny`, `view` (catálogo) | Sí | Sí | Sí | Sí | Sí | Sí |
| `viewManagement` (pantalla de gestión) | No | No | Sí | Sí | No | Sí |
| `create` | No | No | No | No | No | Sí |
| `update` | No | No | No | Los suyos | No | Todos |
| `delete` | No | No | No | No | No | Todos |

`update` cubre la gestión del espacio. Gestionar sus recursos lo decide `ResourcePolicy::create($space)`.

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
