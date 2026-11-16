<x-layouts::app :title="__('Recursos')">
    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">{{ __('Recursos') }}</h1>

            <a href="{{ route('manager.resources.create') }}" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Nuevo recurso') }}</a>
        </div>

        @session('status')
            <p class="text-sm text-green-700">{{ $value }}</p>
        @endsession

        @if ($resources->isEmpty())
            <p class="text-gray-700">{{ __('Todavía no hay recursos.') }}</p>
        @else
            <table class="w-full bg-white text-left text-sm">
                <thead class="border-b border-gray-200">
                    <tr>
                        <th class="p-3 font-medium">{{ __('Espacio') }}</th>
                        <th class="p-3 font-medium">{{ __('Nombre') }}</th>
                        <th class="p-3 font-medium">{{ __('Tipo') }}</th>
                        <th class="p-3 font-medium">{{ __('Capacidad') }}</th>
                        <th class="p-3 font-medium">{{ __('Tarifa por hora') }}</th>
                        <th class="p-3"><span class="sr-only">{{ __('Acciones') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($resources as $resource)
                        <tr class="border-b border-gray-100">
                            <td class="p-3">{{ $resource->space->name }}</td>
                            <td class="p-3">{{ $resource->name }}</td>
                            <td class="p-3">{{ $resource->type->label() }}</td>
                            <td class="p-3">{{ $resource->capacity }}</td>
                            <td class="p-3">{{ number_format((float) $resource->hourly_rate, 2, ',', '.') }} €</td>
                            <td class="p-3">
                                <a href="{{ route('manager.resources.edit', $resource) }}" class="underline">{{ __('Editar') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{ $resources->links() }}
        @endif
    </div>
</x-layouts::app>
