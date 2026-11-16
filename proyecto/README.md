# WorkSpace

Aplicación Laravel para gestionar un coworking: catálogo público y, en la zona privada, reservas de recursos por
franjas, facturación, incidencias e informes. Proyecto de Desarrollo Web en Entorno Servidor (2.º DAW).

La documentación funcional está en [`docs/`](docs): modelo de datos, roles y permisos, reservas, scopes y comandos.

## Puesta en marcha

Pasos para tener la aplicación y la suite de pruebas funcionando desde un clon nuevo. Todos los comandos se ejecutan
dentro de esta carpeta (`proyecto/`).

### Requisitos

- PHP 8.3 o superior (`composer.json` pide `^8.3`) con las extensiones habituales de Laravel y `pdo_sqlite`.
- Composer.
- Node.js y npm.

### 1. Dependencias de PHP

```bash
composer install
```

### 2. Dependencias de JavaScript y compilación de los assets

```bash
npm ci
```

```bash
npm run build
```

`npm ci` instala exactamente las versiones de `package-lock.json`. `npm run build` ejecuta `vp build` (Vite) y genera
`public/build/manifest.json`. **Este paso es obligatorio también para los tests**: las vistas cargan los assets con
`@vite`, y sin el manifest cualquier página responde 500 (`ViteManifestNotFoundException`), así que fallan todos los
tests que abren una vista. `public/build` no se sube a Git, por eso hay que generarlo en cada clon.

### 3. Fichero de entorno y clave

```bash
cp .env.example .env
```

```bash
php artisan key:generate
```

En PowerShell, la copia es `Copy-Item .env.example .env`. `.env.example` ya usa SQLite (`DB_CONNECTION=sqlite`), y por
defecto la base es `database/database.sqlite`.

### 4. Base de datos con datos de demostración

```bash
php artisan migrate:fresh --seed
```

Si `database/database.sqlite` no existe, Artisan pregunta si quiere crearlo: responde que sí. El seeder crea los
espacios, recursos, tarifas, reservas, facturas e incidencias de demostración, y los usuarios de cada rol
(`admin@`, `contable@`, `recepcion@`, `responsable1@`, `responsable2@` y `usuario1@`…`usuario5@workspace.test`, todos
con la contraseña `password`). Son datos inventados, solo para desarrollo.

### 5. Arrancar la aplicación

```bash
composer dev
```

Levanta a la vez `php artisan serve`, la cola y `npm run dev` (Vite con recarga). También vale `php artisan serve` solo,
si ya has hecho `npm run build`.

### 6. Pruebas

```bash
php artisan test
```

```bash
composer test
```

`php artisan test` ejecuta la suite de Pest. `composer test` hace además las comprobaciones de estilo (Pint en modo
`--test`) y de tipos (Larastan) antes de los tests.

**Los tests no usan tu `.env` ni tu base de desarrollo.** `phpunit.xml` fija `APP_ENV=testing`,
`DB_CONNECTION=sqlite` y `DB_DATABASE=:memory:`: cada test trabaja en una base SQLite en memoria que se crea y se
descarta sola. No hay `.env.testing`; toda la configuración de pruebas está en el bloque `<php>` de `phpunit.xml`
(también caché, sesión y correo en memoria y la cola síncrona). De los pasos anteriores necesitan `composer install`,
el manifest de `npm run build` y el `.env` con su clave: `phpunit.xml` no define `APP_KEY`, así que la toma del `.env`
del paso 3. La base de datos del paso 4 no la necesitan.

La prueba de concurrencia (`tests/Concurrency`) necesita MySQL y una base local dedicada `workspace_test`; con SQLite se
omite. El comando y las condiciones están en [`docs/reservas.md`](docs/reservas.md).

### Atajo

`composer setup` encadena `composer install`, la copia de `.env`, `key:generate`, `migrate --force`, `npm install` y
`npm run build`. No carga los datos de demostración (para eso, el paso 4) y usa `npm install` en lugar de `npm ci`.
