<x-layouts::app :title="__('Notificaciones')">
    <div class="mx-auto flex max-w-md flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ __('Notificaciones') }}</h1>

        @session('status')
            <p role="status" class="text-sm text-green-700">{{ $value }}</p>
        @endsession

        <p class="text-sm text-gray-700">
            {{ __('Los avisos por correo electrónico llegan siempre. Además, puedes recibir por Telegram un mensaje cuando se confirma una reserva.') }}
        </p>

        <form method="POST" action="{{ route('account.notifications.update') }}" class="flex flex-col gap-4">
            @csrf
            @method('PUT')

            <x-form.input
                name="telegram_chat_id"
                :label="__('Identificador de chat de Telegram')"
                :value="$user->telegram_chat_id"
                inputmode="numeric"
                autocomplete="off"
                :placeholder="__('Vacío = sin avisos por Telegram')"
            />

            <button type="submit" class="cursor-pointer rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Guardar') }}</button>
        </form>

        <section class="flex flex-col gap-2 text-sm text-gray-700">
            <h2 class="text-base font-semibold text-gray-900">{{ __('Cómo obtener tu identificador') }}</h2>

            <ol class="list-decimal pl-5">
                <li>{{ __('En Telegram, busca el bot del coworking y pulsa «Iniciar»: el bot solo puede escribirte si antes le has hablado.') }}</li>
                <li>{{ __('Para saber tu identificador numérico, escribe a un bot que lo muestre, por ejemplo @userinfobot.') }}</li>
                <li>{{ __('Copia aquí ese número y guarda. Para dejar de recibir avisos, borra el campo y guarda.') }}</li>
            </ol>

            <p>{{ __('El enlace no es automático: la aplicación no comprueba el número con Telegram al guardarlo.') }}</p>
        </section>
    </div>
</x-layouts::app>
