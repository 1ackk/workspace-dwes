<x-layouts::app :title="__('Gestión de espacios')">
    <div class="flex flex-col gap-4">
        <h1 class="text-2xl font-semibold">{{ __('Gestión de espacios') }}</h1>

        <p class="text-gray-700">{{ __('Página provisional para el rol responsable de espacio.') }}</p>

        <a href="{{ route('manager.resources.index') }}" class="font-medium underline">{{ __('Recursos de mis espacios') }}</a>
    </div>
</x-layouts::app>
