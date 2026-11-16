<x-layouts::app :title="__('Nuevo recurso')">
    <div class="mx-auto flex max-w-md flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ __('Nuevo recurso') }}</h1>

        <form method="POST" action="{{ route('manager.resources.store') }}" class="flex flex-col gap-4">
            @csrf

            @include('manager.resources._form', ['resource' => null])

            <button type="submit" class="cursor-pointer rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Crear recurso') }}</button>
        </form>

        <a href="{{ route('manager.resources.index') }}" class="text-sm underline">{{ __('Volver a recursos') }}</a>
    </div>
</x-layouts::app>
