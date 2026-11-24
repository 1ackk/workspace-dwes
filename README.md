# WorkSpace

![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Livewire](https://img.shields.io/badge/Livewire-4-FB70A9?logo=livewire&logoColor=white)
![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-4-06B6D4?logo=tailwindcss&logoColor=white)
![Pest](https://img.shields.io/badge/tests-Pest-F28D1A)

**Gestión de un coworking con Laravel.** Tiene un catálogo público de espacios y una zona privada donde se reservan
recursos (puestos, salas, salas de reuniones, proyectores) por franjas horarias, con precio calculado, control de
disponibilidad y concurrencia, facturación e incidencias.

> Proyecto individual de **Desarrollo Web en Entorno Servidor** (2.º DAW).

---

## Contenido

- [Qué incluye](#qué-incluye)
- [Tecnologías](#tecnologías)
- [Puesta en marcha](#puesta-en-marcha)
- [Usuarios de demostración](#usuarios-de-demostración)
- [Pruebas](#pruebas)
- [Comandos útiles](#comandos-útiles)
- [Límites conocidos](#límites-conocidos)
- [Documentación](#documentación)

---

## Qué incluye

- **Zona pública y privada:** registro, inicio de sesión y rutas ordenadas por área, con middleware de rol.
- **Cinco roles:** usuario, recepción, responsable de espacio, contable y administrador
  ([laravel-permission](https://spatie.be/docs/laravel-permission)), y **cinco policies**.
- **Reservas multirrecurso:** franjas semiabiertas, bloqueo de filas en una transacción para que dos personas no
  reserven a la vez la misma franja, retención de 15 minutos hasta el pago y cancelación con antelación mínima.
- **Precio por tramos:** tarifa base y recargos de tarde y fin de semana, calculados en céntimos enteros y con la hora
  de Madrid.
- **Pantalla de reserva con Livewire** (`/calendar`): rejilla de franjas, precio, reserva y pago simulado.
- **Eventos, colas y correos:** 5 eventos con sus listeners y jobs (3 en la cola `mail`) y 5 correos en español:
  reserva confirmada, cancelada, caducada, recordatorio de reserva próxima y factura vencida.
- **Facturación mensual:** `invoices:generate` crea una factura por usuario con las reservas completadas del mes,
  desde consola o desde el panel de contabilidad.
- **Avisos por Telegram (A2)**, opcionales: canal simulado por defecto y bot real configurable.
- **Siete scopes**, cuatro de ellos complejos: disponibilidad, reservas cancelables, facturas vencidas y ocupación por
  periodo.
- **Siete comandos Artisan**, cuatro programados con el *scheduler* y tres lanzados también desde la web (paneles de
  mantenimiento y de contabilidad).
- **Componentes Blade** de formulario y una regla de validación propia.

---

## Tecnologías

| Parte | Herramienta |
|---|---|
| Backend | PHP 8.3+, Laravel 13, Livewire 4 |
| Roles y permisos | spatie/laravel-permission |
| Frontend | Blade, Tailwind CSS 4, Vite |
| Base de datos | SQLite (desarrollo y tests); MySQL para la prueba de concurrencia |
| Calidad | Pest, Pint, Larastan |

---

## Puesta en marcha

Pasos para tener la aplicación y la suite de pruebas funcionando desde un clon nuevo. Todos los comandos se ejecutan en
la raíz del repositorio.

**Necesitas:** PHP 8.3 o superior con `pdo_sqlite`, Composer, y Node.js con npm.

### 1. Instalar dependencias

```bash
composer install
```

```bash
npm ci
```

`npm ci` instala exactamente las versiones de `package-lock.json`.

### 2. Compilar los assets

```bash
npm run build
```

Ejecuta `vp build` (Vite) y genera `public/build/manifest.json`.

> [!IMPORTANT]
> **Este paso también hace falta para los tests.** Las vistas cargan los assets con `@vite`; sin el manifest, cualquier
> página responde 500 (`ViteManifestNotFoundException`) y fallan todos los tests que abren una vista. `public/build` no
> se sube a Git, así que hay que generarlo en cada clon.

### 3. Crear el fichero de entorno

```bash
cp .env.example .env
```

```bash
php artisan key:generate
```

En PowerShell, la copia es `Copy-Item .env.example .env`. `.env.example` ya viene preparado para desarrollo:

| Variable | Valor | Para qué |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | La base es `database/database.sqlite` |
| `QUEUE_CONNECTION` | `database` | Los jobs van a la tabla `jobs` y los procesa el worker (paso 5) |
| `MAIL_MAILER` | `log` | Los correos se escriben en `storage/logs/laravel.log`; no se envía ninguno |
| `NOTIFICATIONS_CHANNEL` | `simulated` | Los avisos de Telegram se escriben en `storage/logs/notifications.log` |
| `TELEGRAM_BOT_TOKEN` | vacío | Solo para un bot real ([docs/telegram.md](docs/telegram.md)); nunca en Git |
| `INVOICE_DUE_DAYS` | `15` | Días para pagar una factura generada |

### 4. Crear la base de datos con datos de demostración

```bash
php artisan migrate:fresh --seed
```

Si `database/database.sqlite` no existe, Artisan pregunta si quieres crearlo: responde que sí.

### 5. Arrancar

```bash
composer dev
```

Levanta a la vez `php artisan serve`, el worker de la cola (`queue:listen --queue=mail,default`) y `npm run dev` (Vite
con recarga automática).

Si prefieres lanzarlos por separado (con los estilos ya compilados en el paso 2), una terminal para cada uno:

```bash
php artisan serve
```

```bash
php artisan queue:work --queue=mail,default
```

**Sin el worker, los correos en cola no salen.** `--queue=mail,default` es necesario: los correos y los avisos van a la
cola `mail`. Para las tareas programadas, además, `php artisan schedule:work` (ver [docs/comandos.md](docs/comandos.md)).
El guion completo para enseñarlo está en [docs/demostracion.md](docs/demostracion.md).

> [!TIP]
> `composer setup` hace de una vez la instalación, la copia de `.env`, `key:generate`, `migrate --force`,
> `npm install` y `npm run build`. No carga los datos de demostración (para eso, el paso 4) y usa `npm install` en lugar
> de `npm ci`.

---

## Usuarios de demostración

Los crea el seeder. Todos tienen la contraseña `password` y son datos inventados, solo para desarrollo.

| Rol | Correo |
|---|---|
| Administrador | `admin@workspace.test` |
| Contable | `contable@workspace.test` |
| Recepción | `recepcion@workspace.test` |
| Responsable de espacio | `responsable1@workspace.test`, `responsable2@workspace.test` |
| Usuario | `usuario1@workspace.test` … `usuario5@workspace.test` |

---

## Pruebas

```bash
php artisan test
```

```bash
composer test
```

`php artisan test` ejecuta la suite de Pest. `composer test` comprueba además el estilo (Pint en modo `--test`) y los
tipos (Larastan) antes de lanzar los tests.

**Los tests no tocan tu base de desarrollo.** `phpunit.xml` fija `APP_ENV=testing`, `DB_CONNECTION=sqlite` y
`DB_DATABASE=:memory:`, así que cada test trabaja en una base SQLite en memoria que se crea y se descarta sola. No hay
`.env.testing`: toda la configuración de pruebas está en el bloque `<php>` de `phpunit.xml`, que también deja en memoria
la caché, la sesión y el correo, y hace la cola síncrona.

| Necesitan | No necesitan |
|---|---|
| `composer install` | La base de datos del paso 4 |
| El manifest de `npm run build` | |
| El `.env` con su clave (`phpunit.xml` no define `APP_KEY`) | |

La prueba de concurrencia (`tests/Concurrency`) se omite con SQLite: la explica la sección siguiente.

### Prueba de concurrencia (MySQL)

`tests/Concurrency` lanza procesos PHP reales que reservan, cancelan, confirman o facturan **a la vez**, para comprobar
que dos personas no consiguen la misma franja y que nada se hace dos veces. Necesita MySQL porque `lockForUpdate()` no actúa en SQLite: con SQLite estos tests salen
como omitidos, por eso `php artisan test` normal da algunos *skipped*.

**Requisitos:**

1. Un servidor MySQL local en marcha (`127.0.0.1:3306`).
2. Una base de datos vacía y dedicada llamada `workspace_test`:

   ```sql
   CREATE DATABASE workspace_test;
   ```

3. Opcional: el contador de interbloqueos de InnoDB activado, que usa uno de los escenarios. Si está desactivado, ese
   test falla y dice cómo activarlo:

   ```sql
   SET GLOBAL innodb_monitor_enable = 'lock_deadlocks';
   ```

**Ejecutarla** (PowerShell, en la raíz del repositorio; ajusta usuario y contraseña a tu MySQL):

```powershell
$env:DB_CONNECTION='mysql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3306'; $env:DB_DATABASE='workspace_test'; $env:DB_USERNAME='root'; $env:DB_PASSWORD=''; php artisan test --group=concurrency; Remove-Item Env:DB_*
```

Las variables de entorno tienen prioridad sobre `.env` y `phpunit.xml`, así que **ni tu `.env` ni tu base de
desarrollo se tocan**, y `Remove-Item Env:DB_*` las borra al terminar. Por seguridad, la prueba se niega a ejecutarse
si la base no se llama `workspace_test` o el servidor no es local, porque hace `migrate:fresh` sobre ella.

Los escenarios y cómo se consigue la simultaneidad están en [`docs/reservas.md`](docs/reservas.md).

---

## Comandos útiles

| Comando | Qué hace |
|---|---|
| `php artisan migrate:fresh --seed` | Reconstruye la base con los datos de demostración |
| `composer dev` | Servidor, cola y Vite a la vez |
| `php artisan test` | Suite de Pest |
| `composer lint` | Formatea el código con Pint |
| `composer types:check` | Análisis estático con Larastan |
| `composer test` | Estilo, tipos y tests |
| `php artisan queue:work --queue=mail,default` | Worker: procesa los correos y avisos en cola |
| `php artisan queue:failed` / `queue:retry all` | Ver y reintentar los jobs fallidos |
| `php artisan schedule:work` | Ejecuta los comandos programados mientras está abierto |
| `php artisan invoices:generate 2026-10` | Factura un mes ya terminado |

---

## Límites conocidos

Lo que la aplicación **no** cubre todavía, dicho tal cual:

- **No hay tope de reservas pendientes de pago por usuario.** Cada una retiene sus franjas 15 minutos; un usuario
  podría encadenar varias retenciones.
- **El pago es simulado.** No hay pasarela real: el punto de enganche para una (A3) es `BookingService::confirm`.
- **En la web no se puede cancelar una reserva.** `BookingService::cancel` funciona y está probado (y envía su correo),
  pero no tiene todavía botón ni ruta: se usa desde Tinker o desde código.
- **Telegram real no se ha probado contra un bot de verdad**, solo con `Http::fake`. El enlace de la cuenta con el bot es
  manual (no hay webhook de `/start`), y un `429` se reintenta sin leer el `retry_after` de Telegram.
- **Con la cola `sync`, un fallo transitorio de Telegram no se reintenta:** el listener lo registra con `report()` para
  no romper la confirmación. Con la cola `database` sí se reintenta.
- **Concurrencia no demostrada en MySQL:** la colisión real de números de factura entre dos ejecuciones (el reintento
  está probado de forma determinista en SQLite) y liberar frente a confirmar a la vez (sus condiciones se excluyen;
  probado solo con `DB::listen` en SQLite).
- **La pantalla de reserva** crea reservas de un solo recurso, "Descartar" no libera la franja hasta que caduca la
  retención, y la rejilla no se actualiza sola con las reservas de otros usuarios mientras se mira.
- **Listados que faltan:** un usuario no tiene listado de sus facturas ni de sus incidencias (se llega a cada una por
  su URL), y el responsable no tiene listado de las reservas de su espacio.
- **Telegram solo avisa de la confirmación;** los otros cuatro eventos solo envían correo.
- **`invoices:generate` no se programa:** se lanza a mano o desde el panel al cerrar un mes.

---

## Documentación

| Documento | Contenido |
|---|---|
| [`docs/modelo-datos.md`](docs/modelo-datos.md) | Modelos, relaciones y decisiones del esquema |
| [`docs/permisos.md`](docs/permisos.md) | Roles, matriz de permisos y las cinco policies |
| [`docs/reservas.md`](docs/reservas.md) | Disponibilidad, bloqueo, confirmación, cancelación y prueba de concurrencia |
| [`docs/eventos.md`](docs/eventos.md) | Eventos, listeners, jobs, colas y correos; cómo arrancar el worker |
| [`docs/facturacion.md`](docs/facturacion.md) | Generar las facturas de un mes: reglas, número, vencimiento y atomicidad |
| [`docs/livewire.md`](docs/livewire.md) | Componentes Livewire: la pantalla de reserva BookingCalendar |
| [`docs/telegram.md`](docs/telegram.md) | A2: avisos por Telegram, canal simulado y cómo configurar un bot real |
| [`docs/scopes.md`](docs/scopes.md) | Los siete scopes |
| [`docs/comandos.md`](docs/comandos.md) | Los comandos Artisan y su programación |
| [`docs/demostracion.md`](docs/demostracion.md) | Guion de demostración del sprint 5, paso a paso |
| [`docs/sprint-4.md`](docs/sprint-4.md) | Resumen del sprint 4 y su guion de demostración (estado de entonces) |
