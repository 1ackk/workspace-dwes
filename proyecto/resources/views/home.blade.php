<x-layouts::app :title="__('Inicio')">
    <div class="flex flex-col gap-4">
        <h1 class="text-3xl font-semibold">{{ __('Bienvenido a :app', ['app' => config('app.name')]) }}</h1>

        <p class="text-gray-700">{{ __('Reserva puestos, salas y equipos de tu espacio de coworking.') }}</p>
    </div>
</x-layouts::app>
