<?php

use App\Contracts\MessageChannel;
use App\Services\Notifications\PermanentDeliveryException;
use App\Services\Notifications\SimulatedChannel;
use App\Services\Notifications\TelegramChannel;
use App\Services\Notifications\TransientDeliveryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const FAKE_TOKEN = '123456:FAKE-token-solo-para-tests';

beforeEach(function () {
    // Nunca se llama al Telegram real: cualquier petición sin respuesta falsa rompe el test.
    Http::preventStrayRequests();
});

describe('TelegramChannel', function () {
    test('posts chat_id and plain text to sendMessage with the token in the URL', function () {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        (new TelegramChannel(FAKE_TOKEN))->send('100000001', "Línea 1\n*no es negrita*");

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.telegram.org/bot'.FAKE_TOKEN.'/sendMessage'
            && $request['chat_id'] === '100000001'
            && $request['text'] === "Línea 1\n*no es negrita*"
            && ! isset($request['parse_mode']));
    });

    test('a 4xx is a permanent error with the reason and without the token', function (int $status, string $description) {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => $description], $status)]);

        try {
            (new TelegramChannel(FAKE_TOKEN))->send('100000001', 'Hola');
            $this->fail('Debería haber lanzado una excepción.');
        } catch (PermanentDeliveryException $exception) {
            expect($exception->getMessage())->toContain((string) $status)
                ->toContain($description)
                ->not->toContain(FAKE_TOKEN);
        }
    })->with([
        'chat inexistente' => [400, 'Bad Request: chat not found'],
        'bot bloqueado' => [403, 'Forbidden: bot was blocked by the user'],
        'token no válido' => [401, 'Unauthorized'],
    ]);

    test('a 5xx or a 429 is a transient error', function (int $status) {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Too Many Requests: retry after 5'], $status)]);

        expect(fn () => (new TelegramChannel(FAKE_TOKEN))->send('100000001', 'Hola'))
            ->toThrow(TransientDeliveryException::class);
    })->with([429, 500, 502, 503]);

    test('a timeout is transient and its message does not leak the token in the URL', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for https://api.telegram.org/bot'.FAKE_TOKEN.'/sendMessage'));

        try {
            (new TelegramChannel(FAKE_TOKEN))->send('100000001', 'Hola');
            $this->fail('Debería haber lanzado una excepción.');
        } catch (TransientDeliveryException $exception) {
            expect($exception->getMessage())->toBe('No se pudo conectar con Telegram (timeout o red).')
                ->and($exception->getPrevious())->toBeNull();
        }
    });

    test('uses the configured timeout', function () {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        (new TelegramChannel(FAKE_TOKEN, 3))->send('100000001', 'Hola');

        Http::assertSent(fn (Request $request) => true);
        expect((new ReflectionProperty(TelegramChannel::class, 'timeout'))->getValue(new TelegramChannel(FAKE_TOKEN, 3)))->toBe(3);
    });

    test('without a token it fails early with a clear message', function () {
        new TelegramChannel('');
    })->throws(InvalidArgumentException::class, 'Falta TELEGRAM_BOT_TOKEN: el canal de Telegram necesita el token del bot.');
});

describe('SimulatedChannel', function () {
    test('writes the message to the notifications log with the recipient masked', function () {
        $log = Mockery::mock();
        $log->shouldReceive('info')->once()->with('Mensaje simulado', ['to' => '*****0001', 'text' => 'Hola']);
        Log::shouldReceive('channel')->once()->with('notifications')->andReturn($log);

        (new SimulatedChannel)->send('100000001', 'Hola');

        Http::assertNothingSent();
    });

    test('masks every recipient leaving only the last four characters', function (string $recipient, string $masked) {
        expect(SimulatedChannel::mask($recipient))->toBe($masked);
    })->with([
        ['100000001', '*****0001'],
        ['-1001234567890', '**********7890'],
        ['1234', '****'],
        ['12', '**'],
    ]);

    test('the notifications log channel writes to storage/logs/notifications.log', function () {
        expect(config('logging.channels.notifications.path'))->toBe(storage_path('logs/notifications.log'));
    });
});

describe('selection by configuration', function () {
    test('the simulated channel is the default', function () {
        expect(config('services.notifications.channel'))->toBe('simulated')
            ->and(app(MessageChannel::class))->toBeInstanceOf(SimulatedChannel::class);
    });

    test('telegram with a token resolves the Telegram channel', function () {
        config(['services.notifications.channel' => 'telegram', 'services.telegram.bot_token' => FAKE_TOKEN]);

        expect(app(MessageChannel::class))->toBeInstanceOf(TelegramChannel::class);
    });

    test('telegram without a token fails when the channel is resolved', function () {
        config(['services.notifications.channel' => 'telegram', 'services.telegram.bot_token' => null]);

        app(MessageChannel::class);
    })->throws(InvalidArgumentException::class, 'Falta TELEGRAM_BOT_TOKEN');

    test('an unknown channel fails with a clear message', function () {
        config(['services.notifications.channel' => 'sms']);

        app(MessageChannel::class);
    })->throws(InvalidArgumentException::class, 'NOTIFICATIONS_CHANNEL no válido: "sms". Usa "simulated" o "telegram".');

    test('the example env and the tests keep the token empty', function () {
        $example = (string) file_get_contents(base_path('.env.example'));

        expect($example)->toContain("NOTIFICATIONS_CHANNEL=simulated\n")
            ->toContain("TELEGRAM_BOT_TOKEN=\n")
            ->and(config('services.telegram.bot_token'))->toBeEmpty();
    });
});
