<x-layouts::app :title="__('Editar recurso')">
    <div class="mx-auto flex max-w-md flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ __('Editar recurso') }}</h1>

        <form method="POST" action="{{ route('manager.resources.update', $resource) }}" class="flex flex-col gap-4">
            @csrf
            @method('PUT')

            @include('manager.resources._form')

            <button type="submit" class="cursor-pointer rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Guardar cambios') }}</button>
        </form>

        <a href="{{ route('manager.resources.index') }}" class="text-sm underline">{{ __('Volver a recursos') }}</a>
    </div>
</x-layouts::app>
