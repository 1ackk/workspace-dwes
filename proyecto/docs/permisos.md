# WorkSpace · Roles y permisos (sprint 3)

Los roles y permisos se crean en `database/seeders/RoleSeeder.php` con laravel-permission. Los
permisos dicen qué acción puede hacer un rol; qué registros concretos le corresponden lo
decidirán las policies.

## Roles

- **usuario**: miembro del coworking; reserva, paga las suyas y abre incidencias. Es el rol que recibe quien se registra desde la web.
- **recepción**: atiende en el mostrador; consulta espacios, recursos y usuarios, y gestiona reservas e incidencias en nombre de los miembros.
- **responsable de espacio**: gestiona su espacio y sus recursos, y atiende sus reservas e incidencias.
- **contable**: gestiona facturas y consulta pagos e informes.
- **administrador**: tiene todos los permisos.

## Matriz

- `✓`: tiene el permiso.
- `*`: la policy lo limitará a lo suyo (sus reservas, sus espacios, sus recursos…).
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
| `bookings.view` | ✓* | ✓† | ✓* | | ✓ |
| `bookings.create` | ✓ | ✓† | | | ✓ |
| `bookings.cancel` | ✓* | ✓† | | | ✓ |
| `incidents.view-any` | | ✓† | ✓* | | ✓ |
| `incidents.view` | ✓* | ✓† | ✓* | | ✓ |
| `incidents.create` | ✓ | ✓† | ✓† | | ✓ |
| `incidents.update` | | | ✓* | | ✓ |
| `incidents.respond` | ✓* | | ✓* | | ✓ |
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
- `incidents.update` sirve para cambiar el estado de una incidencia; `incidents.respond`, solo para comentar.
- `payments.create` permite al usuario pagar su propia reserva.
