# WorkSpace · A2: avisos por Telegram

A2 es la ampliación "otro tipo de notificaciones": cuando una reserva se confirma, el titular recibe,
además del correo, un mensaje por Telegram si lo ha activado. Es un extra: **el núcleo funciona entero
sin él**, y por defecto (y siempre en las pruebas) se usa un canal **simulado** que no sale a Internet.

## Cómo se reparte

| Pieza | Qué hace |
|---|---|
| `App\Contracts\MessageChannel` | Interfaz con un solo método: `send(string $recipient, string $message)` |
| `App\Services\Notifications\SimulatedChannel` | Por defecto. Escribe el mensaje en `storage/logs/notifications.log`, con el destinatario enmascarado (`*****0001`) |
| `App\Services\Notifications\TelegramChannel` | Llama a `sendMessage` de la API de bots con el cliente HTTP de Laravel |
| `AppServiceProvider::register()` | Elige la implementación según `NOTIFICATIONS_CHANNEL` |
| `App\Listeners\SendBookingConfirmedTelegram` | Segundo listener de `BookingConfirmed`: solo despacha el job si el titular tiene Telegram activado |
| `App\Jobs\SendBookingConfirmedTelegramJob` | En la cola `mail`: compone el texto y lo envía por el canal |
| `users.telegram_chat_id` | Identificador de chat del usuario; nulo = sin Telegram |
| `account/notifications` | Pantalla "Notificaciones" donde el usuario lo escribe o lo borra |

**No hay evento nuevo:** se reutiliza `BookingConfirmed`, que ya tiene el listener del correo. El
correo y el mensaje son dos jobs independientes: si Telegram falla, la confirmación y el correo no se
ven afectados.

**Recuento.** El núcleo sigue siendo 5 eventos, 5 listeners y 5 jobs (3 en cola) para M7 y M8. A2
añade **1 listener y 1 job** como extensión (6 y 6 en total). No añade eventos, comandos ni scopes: 5
eventos, 7 comandos (3 llamados desde código) y 7 scopes. `tests/Feature/Project/InventoryTest.php`
lo comprueba.

## El mensaje

Texto plano, corto y en español:

```
WorkSpace: reserva confirmada.
- Sala Azul (Planta Baja): 02/12/2026, 16:00–18:00
Total: 24,00 €
```

Sin email ni otros datos personales. Se envía **sin `parse_mode`**: Telegram no interpreta Markdown ni
HTML, así que el nombre de un recurso no puede meter formato ni enlaces.

## Fallos

| Situación | Excepción | Qué hace el job |
|---|---|---|
| 4xx (chat inexistente, bot bloqueado, token no válido) | `PermanentDeliveryException` | `fail()` en el acto: no se reintenta y se registra |
| 5xx, timeout, error de red, 429 (demasiados envíos) | `TransientDeliveryException` | Se vuelve a lanzar: la cola lo reintenta (3 intentos, esperas de 10 y 60 s) |

- **El job no envía nada** si, al ejecutarse, la reserva ya no está confirmada, el usuario no existe
  (o está borrado lógicamente) o ha desactivado Telegram.
- **`failed()`** deja en el log el id de la reserva y el error, nunca el token ni el identificador.
- **El token nunca sale en un error:** va dentro de la URL de la API, y los errores de conexión de
  Guzzle incluyen la URL, así que `TelegramChannel` no copia nunca el mensaje original: lanza el suyo.
- **Con la cola `sync`** el job se ejecuta dentro de la confirmación; el listener captura cualquier
  fallo con `report()` para que un error de Telegram no convierta la confirmación en un error 500.

## Configuración

```dotenv
NOTIFICATIONS_CHANNEL=simulated   # simulated (por defecto) o telegram
TELEGRAM_BOT_TOKEN=               # solo con telegram; nunca en el repositorio
```

Con `telegram` y sin token, la aplicación falla en cuanto pide el canal, con el mensaje "Falta
TELEGRAM_BOT_TOKEN…". Cualquier otro valor de `NOTIFICATIONS_CHANNEL` también da error. `phpunit.xml`
fija `simulated` con el token vacío.

### Modo simulado (por defecto)

No hace falta nada. Cada mensaje aparece al final de `storage/logs/notifications.log`:

```
[…] local.INFO: Mensaje simulado {"to":"*****0001","text":"WorkSpace: reserva confirmada.\n- …"}
```

El seeder deja a `usuario1@workspace.test` con el identificador ficticio `100000001`, así que sus
confirmaciones generan un mensaje simulado.

### Un bot real

1. En Telegram, habla con **@BotFather**, envía `/newbot` y sigue sus pasos: te dará el **token** del
   bot (algo como `123456789:AA…`).
2. Busca tu bot por su nombre y pulsa **Iniciar**: un bot solo puede escribir a quien le ha hablado
   antes.
3. Para saber tu **identificador de chat**, escribe a un bot que lo muestre (por ejemplo
   **@userinfobot**) y copia el número.
4. En tu `.env` (nunca en `.env.example` ni en Git):

   ```dotenv
   NOTIFICATIONS_CHANNEL=telegram
   TELEGRAM_BOT_TOKEN=el-token-de-botfather
   ```

   y después `php artisan config:clear`.
5. Entra en la aplicación, abre **Notificaciones**, pega tu identificador y guarda.
6. Con el worker en marcha (`php artisan queue:work --queue=mail,default`), confirma una reserva: te
   llegará el correo y el mensaje.

## La pantalla de notificaciones

`GET/PUT account/notifications`, en la zona privada. Siempre actúa sobre el usuario autenticado (la
ruta no lleva id). `UpdateNotificationSettingsRequest` valida el identificador: vacío para
desactivar, o un número de 5 a 15 cifras que puede empezar por un signo menos (los grupos tienen id
negativo), y único entre usuarios. `telegram_chat_id` **no está en `Fillable`**: el registro u otro
formulario no pueden rellenarlo por asignación masiva; solo esta pantalla lo cambia, con `forceFill`.

## Límites conocidos

- **El enlace no es automático.** El usuario copia su identificador a mano; no hay webhook que reciba
  el `/start` del bot y enlace la cuenta solo, ni se comprueba el número con Telegram al guardarlo.
- **Solo la confirmación** avisa por Telegram; los otros cuatro eventos solo envían correo.
- **Un 429** se reintenta con el backoff fijo del job, sin leer el `retry_after` que manda Telegram.
- **Un error permanente** (por ejemplo, el usuario bloqueó el bot) no desactiva Telegram en su cuenta:
  cada confirmación volverá a fallar y a registrarse hasta que lo borre en Notificaciones.
