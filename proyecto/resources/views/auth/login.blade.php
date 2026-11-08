<x-layouts::app :title="__('Iniciar sesión')">
    <div class="mx-auto flex max-w-md flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ __('Iniciar sesión') }}</h1>

        <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-4">
            @csrf

            <x-form.input name="email" type="email" :label="__('Correo electrónico')" required autofocus autocomplete="email" />
            <x-form.input name="password" type="password" :label="__('Contraseña')" required autocomplete="current-password" />
            <x-form.checkbox name="remember" :label="__('Recordarme')" />

            <button type="submit" class="cursor-pointer rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Entrar') }}</button>
        </form>

        <p class="text-sm text-gray-700">
            {{ __('¿No tienes cuenta?') }}
            <a href="{{ route('register') }}" class="font-medium underline">{{ __('Crear cuenta') }}</a>
        </p>
    </div>
</x-layouts::app>
