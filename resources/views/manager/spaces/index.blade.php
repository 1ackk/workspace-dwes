<x-layouts::app :title="__('Espacios')">
    <div class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ __('Espacios') }}</h1>

        @session('status')
            <p class="text-sm text-green-700">{{ $value }}</p>
        @endsession

        @if ($spaces->isEmpty())
            <p class="text-gray-700">{{ __('No gestionas ningún espacio.') }}</p>
        @else
            <table class="w-full bg-white text-left text-sm">
                <thead class="border-b border-gray-200">
                    <tr>
                        <th class="p-3 font-medium">{{ __('Nombre') }}</th>
                        <th class="p-3 font-medium">{{ __('Ubicación') }}</th>
                        <th class="p-3 font-medium">{{ __('Recursos') }}</th>
                        <th class="p-3 font-medium">{{ __('Antelación de cancelación') }}</th>
                        <th class="p-3"><span class="sr-only">{{ __('Acciones') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($spaces as $space)
                        <tr class="border-b border-gray-100">
                            <td class="p-3">{{ $space->name }}</td>
                            <td class="p-3">{{ $space->location }}</td>
                            <td class="p-3">{{ $space->resources_count }}</td>
                            <td class="p-3">{{ $space->cancellation_notice_hours }} h</td>
                            <td class="p-3">
                                <a href="{{ route('manager.spaces.edit', $space) }}" class="underline">{{ __('Editar') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-layouts::app>
