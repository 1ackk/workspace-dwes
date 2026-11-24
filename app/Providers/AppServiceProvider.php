<?php

namespace App\Providers;

use App\Contracts\MessageChannel;
use App\Services\Notifications\SimulatedChannel;
use App\Services\Notifications\TelegramChannel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Registra servicios de la aplicación.
     */
    public function register(): void
    {
        // A2: el canal de mensajes se elige por configuración. Con "telegram" y sin token, falla
        // aquí mismo, al pedir el canal, con un mensaje claro (ver TelegramChannel).
        $this->app->bind(MessageChannel::class, function (): MessageChannel {
            $channel = config('services.notifications.channel');

            return match ($channel) {
                'simulated' => new SimulatedChannel,
                'telegram' => new TelegramChannel(
                    (string) config('services.telegram.bot_token'),
                    config()->integer('services.telegram.timeout'),
                ),
                default => throw new InvalidArgumentException(__('NOTIFICATIONS_CHANNEL no válido: ":value". Usa "simulated" o "telegram".', [
                    'value' => is_string($channel) ? $channel : '',
                ])),
            };
        });
    }

    /**
     * Configura la aplicación al arrancar.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Comportamientos por defecto pensados para producción.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
