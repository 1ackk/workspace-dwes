<x-layouts::app :title="__('Administración')">
    <div class="flex flex-col gap-4">
        <h1 class="text-2xl font-semibold">{{ __('Administración') }}</h1>

        <p class="text-gray-700">{{ __('Página provisional para el rol administrador.') }}</p>

        <a href="{{ route('manager.resources.index') }}" class="font-medium underline">{{ __('Recursos') }}</a>
    </div>
</x-layouts::app>
