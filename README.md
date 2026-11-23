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
- **Siete scopes**, cuatro de ellos complejos: disponibilidad, reservas cancelables, facturas vencidas y ocupación por
  periodo.
- **Cuatro comandos Artisan** programados con el *scheduler*; dos se lanzan también desde el panel de administración.
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

En PowerShell, la copia es `Copy-Item .env.example .env`. `.env.example` ya viene con SQLite (`DB_CONNECTION=sqlite`),
y la base por defecto es `database/database.sqlite`.

### 4. Crear la base de datos con datos de demostración

```bash
php artisan migrate:fresh --seed
```

Si `database/database.sqlite` no existe, Artisan pregunta si quieres crearlo: responde que sí.

### 5. Arrancar

```bash
composer dev
```

Levanta a la vez `php artisan serve`, la cola y `npm run dev` (Vite con recarga automática). Si ya hiciste
`npm run build`, también basta con `php artisan serve`.

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

`tests/Concurrency` lanza procesos PHP reales que reservan o cancelan **a la vez**, para comprobar que dos personas no
consiguen la misma franja. Necesita MySQL porque `lockForUpdate()` no actúa en SQLite: con SQLite estos tests salen
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
| `php artisan schedule:work` | Ejecuta los comandos programados mientras está abierto |

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
| [`docs/scopes.md`](docs/scopes.md) | Los siete scopes |
| [`docs/comandos.md`](docs/comandos.md) | Los comandos Artisan y su programación |
| [`docs/sprint-4.md`](docs/sprint-4.md) | Resumen del sprint 4 y guion de demostración |
