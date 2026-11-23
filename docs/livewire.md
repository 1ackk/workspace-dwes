# WorkSpace · Componentes Livewire

**Contador del proyecto (mínimo M10):** 1 de 7 clases Livewire, 0 de 5 CRUD.

| Clase | Tipo | Ruta | Sprint |
|---|---|---|---|
| `App\Livewire\BookingCalendar` | No CRUD (pantalla de reserva) | `GET calendar` (`calendar`) | 5 |

Formato: componentes **de clase** (`app/Livewire/*.php` con su vista en `resources/views/livewire/`),
el más fácil de localizar y de probar con `Livewire::test()`. Livewire 4 los pinta dentro de
`layouts::app`, el layout de siempre, sin configuración extra.

## BookingCalendar

La pantalla donde se ve el flujo entero del sprint 5: elegir recurso y día, ver la rejilla de franjas
de 30 minutos, elegir un tramo, ver el precio, **reservar** (reserva pendiente con retención) y
**pagar** de forma simulada (se confirma, se lanza `BookingConfirmed` y sale el correo).

- **Acceso:** `Route::livewire('calendar', …)` en la zona privada con `can:create,Booking`
  (`BookingPolicy::create`, permiso `bookings.create`): usuario, recepción y administrador. El
  responsable y el contable reciben 403; un visitante va al login. Enlace "Reservar" en el menú y en el
  panel, solo para quien puede reservar.
- **Selectores:** recurso (solo los activos, con su espacio: "Planta Baja · Sala 1") y día (por
  defecto, mañana; un día pasado da error de validación). Son los componentes `x-form.select` y
  `x-form.date` con `wire:model.live`. Cambiar de recurso o de día borra la selección.
- **Rejilla:** una celda por bloque de 30 minutos desde la apertura hasta el cierre del espacio, con su
  estado en texto ("Libre", "Ocupada", "Pasada", "Elegida"), color y `aria-label` ("10:00–10:30,
  Libre"). Las celdas son `<button>`: se usan con el teclado, y las que no se pueden elegir están
  `disabled`.
- **Elegir un tramo:** primer clic, inicio; segundo clic, final (incluido). Si el segundo es anterior
  al primero se intercambian; dos clics en la misma celda son 30 minutos. Si el tramo cruza una celda
  ocupada o pasada se rechaza con un mensaje y no queda a medias. Un tercer clic empieza otra selección.
- **Precio:** vista previa con `PriceCalculator::priceSlot()`, el mismo cálculo que el servicio
  (recargos incluidos). Es orientativa: el precio que se guarda lo vuelve a calcular el servicio.
- **Reservar:** `BookingService::createPending()` con un único `Slot`. Si el servicio rechaza (otro
  usuario se adelantó, fuera de horario, pasada…), se muestra su mensaje y la rejilla se refresca, sin
  error 500.
- **Pagar (simulado):** recarga la reserva, `Gate::authorize('confirm', $booking)` y
  `BookingService::confirm()`. Si va bien, mensaje con enlace al detalle en Mis reservas; el correo lo
  envía el evento `BookingConfirmed` (ver [eventos.md](eventos.md)). Si la retención caducó, mensaje
  claro y rejilla refrescada.
- **Descartar:** oculta el panel y deja que la retención caduque sola (como mucho 15 minutos). No
  cancela la reserva: `cancel()` exige la antelación del espacio (una reserva para dentro de pocas
  horas no se podría descartar) y enviaría el correo "Reserva cancelada" de algo que nunca se pagó.
- **Caducidad en pantalla:** mientras hay una retención, `wire:poll.15s="checkHold"` comprueba cada 15
  segundos si ha caducado y, si es así, lo avisa y vuelve a la rejilla.

## Reparto entre componente y servicios

El componente es fino: guarda lo que ha elegido el usuario y llama a los servicios.

| Qué | Dónde |
|---|---|
| Celdas y su estado | `App\Services\Booking\AvailabilityGrid` (con `GridCell` y el enum `CellState`) |
| Precio | `App\Services\Pricing\PriceCalculator` |
| Crear y confirmar la reserva, con todas sus reglas | `App\Services\Booking\BookingService` |
| Quién puede reservar y pagar | `BookingPolicy` (`create` y `confirm`) |

`AvailabilityGrid` hace como mucho **dos consultas**, haya las reservas que haya: el espacio del
recurso (si no venía cargado) y las franjas del recurso que solapan el día, filtradas con
`Booking::blocking()`, la misma regla que el scope `available` (una pendiente con la retención vencida
no ocupa). Una celda es "pasada" si empieza ahora o antes, la misma regla con la que `createPending`
rechaza una franja en el pasado. El tamaño de la celda es `BookingService::BLOCK_MINUTES`. No crea
ningún scope: siguen siendo 7.

## Seguridad

Las propiedades públicas de un componente Livewire viajan al navegador y el cliente puede cambiarlas.
Por eso:

- **`#[Locked]`** en `pendingBookingId` y `confirmedBookingId`: si el cliente intenta cambiarlos,
  Livewire lanza `CannotUpdateLockedPropertyException`.
- **Se recarga y se reautoriza en cada acción:** `pay()` vuelve a leer la reserva de la base de datos
  y llama a `Gate::authorize('confirm', …)`; `reserve()` llama a `Gate::authorize('create', …)`. Una
  reserva que no es del usuario da 403.
- **Validación** de `resourceId` (recurso activo que existe), `day` (fecha `Y-m-d` real, de hoy en
  adelante) y las horas elegidas (`H:i`), con mensajes en español. Una fecha o una hora manipulada no
  rompe la pantalla: se comprueba antes de convertirla.
- **El servicio no se fía de la pantalla:** `createPending()` vuelve a comprobar la rejilla de 30
  minutos, el horario, el pasado y la disponibilidad bajo bloqueo, y calcula el precio. El precio
  mostrado nunca se guarda.
- Los errores de negocio (`BookingException`) se muestran como mensajes, no como excepciones.

## Límites conocidos

- **Una reserva por pantalla y un solo recurso:** el servicio admite reservas multirrecurso, pero esta
  pantalla crea una reserva con una sola franja.
- **Descartar no libera al momento:** la franja queda ocupada hasta que acaba la retención. Liberarla
  ya pediría un método nuevo del servicio que no lanzara el correo de caducidad.
- **La rejilla no se actualiza sola** con las reservas de otros usuarios mientras se mira: se refresca
  al cambiar de recurso o de día y después de cada acción. Si otro usuario se adelanta, el servicio lo
  detecta al reservar.
- **Solo un día cada vez**, sin vista semanal.

## Estilos

Las clases de Tailwind nuevas están en las vistas Blade, que Tailwind 4 recorre solo. Tras cambiar una
vista hay que recompilar los estilos con `npm run build` (o tener `npm run dev` en marcha).
