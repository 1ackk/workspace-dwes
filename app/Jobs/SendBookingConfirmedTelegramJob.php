<?php

namespace App\Jobs;

use App\Contracts\MessageChannel;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Services\Notifications\PermanentDeliveryException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A2: avisa por el canal de mensajes (Telegram o el simulado) de una reserva confirmada. Va a la
 * cola "mail", la misma que los correos, para no necesitar otro worker.
 *
 * Un error permanente (chat inexistente, bot bloqueado) no se reintenta: fail() lo da por fallido
 * en el acto. Un error transitorio (caída, timeout, 429) se vuelve a lanzar y la cola lo reintenta.
 */
class SendBookingConfirmedTelegramJob implements ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'mail';

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Booking $booking)
    {
        $this->onQueue(self::QUEUE);
        $this->afterCommit();
    }

    public function handle(MessageChannel $channel): void
    {
        $user = $this->booking->user;

        // Solo si sigue siendo cierto: reserva confirmada, titular existente y con Telegram activado.
        if ($this->booking->status !== BookingStatus::Confirmed || $user === null || $user->telegram_chat_id === null) {
            return;
        }

        try {
            $channel->send($user->telegram_chat_id, $this->message());
        } catch (PermanentDeliveryException $exception) {
            $this->fail($exception);
        }
    }

    /**
     * Texto plano y corto: qué se ha confirmado y el total. Sin email ni otros datos personales.
     */
    public function message(): string
    {
        $this->booking->load('slots.resource.space');

        $lines = $this->booking->slots
            ->sortBy(fn (BookingResource $slot) => $slot->start_at->getTimestamp())
            ->map(fn (BookingResource $slot) => sprintf(
                '- %s (%s): %s, %s–%s',
                $slot->resource->name,
                $slot->resource->space->name,
                $slot->start_at->format('d/m/Y'),
                $slot->start_at->format('H:i'),
                $slot->end_at->format('H:i'),
            ));

        return implode("\n", [
            __(':app: reserva confirmada.', ['app' => config('app.name')]),
            ...$lines->all(),
            __('Total: :total €', ['total' => number_format((float) $this->booking->total, 2, ',', '.')]),
        ]);
    }

    /**
     * Se llama al agotar los intentos o con fail(). Se registra la reserva y el tipo de error, nunca
     * el token ni el identificador de chat.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('No se pudo enviar el aviso por Telegram de la reserva confirmada.', [
            'booking_id' => $this->booking->id,
            'error' => $exception === null ? null : $exception::class.': '.$exception->getMessage(),
        ]);
    }
}
