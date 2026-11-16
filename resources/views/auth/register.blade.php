<x-layouts::app :title="__('Crear cuenta')">
    <div class="mx-auto flex max-w-md flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ __('Crear cuenta') }}</h1>

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-4">
            @csrf

            <x-form.input name="name" :label="__('Nombre')" required autofocus autocomplete="name" />
            <x-form.input name="email" type="email" :label="__('Correo electrónico')" required autocomplete="email" />
            <x-form.input name="password" type="password" :label="__('Contraseña')" required autocomplete="new-password" />
            <x-form.input name="password_confirmation" type="password" :label="__('Confirmar contraseña')" required autocomplete="new-password" />

            <button type="submit" class="cursor-pointer rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Crear cuenta') }}</button>
        </form>

        <p class="text-sm text-gray-700">
            {{ __('¿Ya tienes cuenta?') }}
            <a href="{{ route('login') }}" class="font-medium underline">{{ __('Iniciar sesión') }}</a>
        </p>
    </div>
</x-layouts::app>
