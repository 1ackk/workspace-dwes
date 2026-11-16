@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ? $title.' - ' : '' }}{{ config('app.name') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-screen flex-col bg-gray-50 font-sans text-gray-900 antialiased">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
                <a href="{{ route('home') }}" class="text-lg font-semibold">{{ config('app.name') }}</a>

                <nav class="flex items-center gap-4 text-sm">
                    @auth
                        <a href="{{ route('dashboard') }}" class="hover:underline">{{ __('Panel') }}</a>
                        <a href="{{ route('my-bookings.index') }}" class="hover:underline">{{ __('Mis reservas') }}</a>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="cursor-pointer hover:underline">{{ __('Cerrar sesión') }}</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="hover:underline">{{ __('Iniciar sesión') }}</a>
                        <a href="{{ route('register') }}" class="hover:underline">{{ __('Crear cuenta') }}</a>
                    @endauth
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-8">
            {{ $slot }}
        </main>
    </body>
</html>
